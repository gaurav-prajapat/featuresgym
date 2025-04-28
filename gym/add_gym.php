<?php
session_start();
require '../config/database.php';
if (!isset($_SESSION['owner_id'])) {
    header('Location: ../login.php');
    exit;
}

// Get owner details
$ownerId = $_SESSION['owner_id'];
$db = new GymDatabase();
$conn = $db->getConnection();

// Check if owner has reached maximum gym limit
$checkLimitSql = "SELECT gym_limit FROM gym_owners WHERE id = ?";
$checkLimitStmt = $conn->prepare($checkLimitSql);
$checkLimitStmt->execute([$ownerId]);
$gymLimit = $checkLimitStmt->fetchColumn();

$countGymsSql = "SELECT COUNT(*) FROM gyms WHERE owner_id = ? AND status != 'deleted'";
$countGymsStmt = $conn->prepare($countGymsSql);
$countGymsStmt->execute([$ownerId]);
$currentGymCount = $countGymsStmt->fetchColumn();

$limitReached = ($gymLimit !== null && $currentGymCount >= $gymLimit);

// Get amenities from the amenities table
$amenitiesSql = "SELECT id, name, category FROM amenities ORDER BY category, name";
$amenitiesStmt = $conn->query($amenitiesSql);
$amenitiesList = $amenitiesStmt->fetchAll(PDO::FETCH_ASSOC);

// Group amenities by category
$amenitiesByCategory = [];
foreach ($amenitiesList as $amenity) {
    if (!isset($amenitiesByCategory[$amenity['category']])) {
        $amenitiesByCategory[$amenity['category']] = [];
    }
    $amenitiesByCategory[$amenity['category']][] = $amenity;
}

// Get equipment categories
$categoriesSql = "SELECT DISTINCT category FROM amenities WHERE category = 'Equipment' ORDER BY name";
$categoriesStmt = $conn->query($categoriesSql);
$equipmentCategories = $categoriesStmt->fetchAll(PDO::FETCH_COLUMN);

// Add common categories if not in the list
$commonCategories = ['Cardio', 'Strength', 'Free Weights', 'Machines', 'Functional Training', 'Yoga/Pilates', 'Accessories'];
foreach ($commonCategories as $category) {
    if (!in_array($category, $equipmentCategories)) {
        $equipmentCategories[] = $category;
    }
}
sort($equipmentCategories);

// Include navbar
include '../includes/navbar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Add Gym Details | Fitness Hub</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    .required-field::after {
      content: '*';
      color: #ef4444;
      margin-left: 4px;
    }
    .section-card {
      transition: all 0.3s ease;
    }
    .section-card:hover {
      box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    }
    /* Custom scrollbar */
    ::-webkit-scrollbar {
      width: 8px;
      height: 8px;
    }
    ::-webkit-scrollbar-track {
      background: #f1f1f1;
      border-radius: 10px;
    }
    ::-webkit-scrollbar-thumb {
      background: #888;
      border-radius: 10px;
    }
    ::-webkit-scrollbar-thumb:hover {
      background: #555;
    }
    /* Animations */
    .animate-fade-in {
      animation: fadeIn 0.5s ease-out;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    /* Form focus effects */
    input:focus, select:focus, textarea:focus {
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.3);
    }
  </style>
</head>
<body class="bg-gray-100 dark:bg-gray-900 text-gray-800 dark:text-white min-h-screen flex flex-col">
  <div class="container mx-auto py-10 px-4 flex-grow">
    <div class="max-w-6xl mx-auto">
      <!-- Header with progress indicator -->
      <div class="bg-white dark:bg-gray-800 shadow-lg rounded-xl p-6 mb-8 animate-fade-in">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between">
          <div class="mb-4 md:mb-0">
            <h1 class="text-3xl font-bold  dark:text-white">Add Your Gym</h1>
            <p class="text-gray-600 dark:text-gray-400 mt-1">Complete the form below to add your gym to our platform</p>
          </div>
          <div class="flex items-center space-x-2">
            <span class="text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap" id="progress-text">0% Complete</span>
            <div class="w-32 md:w-48 bg-gray-200 dark:bg-gray-700 rounded-full h-2.5">
              <div class="bg-blue-600 h-2.5 rounded-full" id="progress-bar" style="width: 0%"></div>
            </div>
          </div>
        </div>
      </div>
     
      <?php if ($limitReached): ?>
      <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-6 rounded-xl shadow-md mb-8 animate-fade-in" role="alert">
        <div class="flex items-start">
          <div class="flex-shrink-0">
            <i class="fas fa-exclamation-circle text-red-500 text-2xl mr-4"></i>
          </div>
          <div>
            <p class="font-bold text-lg mb-1">Gym Limit Reached</p>
            <p>You have reached your maximum limit of <?php echo $gymLimit; ?> gyms. Please contact support to increase your limit or upgrade your account.</p>
            <a href="../contact.php" class="inline-block mt-3 bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
              Contact Support
            </a>
          </div>
        </div>
      </div>
      <?php else: ?>
      
      <!-- Display error message if it exists in the session -->
      <?php if (isset($_SESSION['error_message'])): ?>
      <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-6 rounded-xl shadow-md mb-8 animate-fade-in" role="alert">
        <div class="flex items-start">
          <div class="flex-shrink-0">
            <i class="fas fa-exclamation-circle text-red-500 text-2xl mr-4"></i>
          </div>
          <div>
            <p class="font-bold text-lg mb-1">Error</p>
            <p><?php echo $_SESSION['error_message']; ?></p>
          </div>
        </div>
      </div>
      <?php 
        // Clear the error message after displaying it
        unset($_SESSION['error_message']);
      endif; ?>
      
      <!-- Display success message if it exists in the session -->
      <?php if (isset($_SESSION['success_message'])): ?>
      <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-6 rounded-xl shadow-md mb-8 animate-fade-in" role="alert">
        <div class="flex items-start">
          <div class="flex-shrink-0">
            <i class="fas fa-check-circle text-green-500 text-2xl mr-4"></i>
          </div>
          <div>
            <p class="font-bold text-lg mb-1">Success</p>
            <p><?php echo $_SESSION['success_message']; ?></p>
          </div>
        </div>
      </div>
      <?php 
        // Clear the success message after displaying it
        unset($_SESSION['success_message']);
      endif; ?>
      
      <form action="process_add_gym.php" method="POST" enctype="multipart/form-data" class="space-y-8">
        
        <!-- Basic Information -->
        <div class="section-card bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-6 shadow-md animate-fade-in">
          <h2 class="text-xl font-semibold text-gray-800 dark:text-white mb-6 flex items-center">
            <div class="bg-blue-100 dark:bg-blue-900 p-2 rounded-lg mr-3">
              <i class="fas fa-info-circle text-blue-500 dark:text-blue-400"></i>
            </div>
            Basic Information
          </h2>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <label for="gym_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Gym Name</label>
              <input type="text" id="gym_name" name="gym_name" placeholder="Enter gym name" 
                     class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200" required>
            </div>
            <div>
              <label for="address" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Address</label>
              <input type="text" id="address" name="address" placeholder="Enter street address" 
                     class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200" required>
            </div>
            <div>
              <label for="city" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">City</label>
              <input type="text" id="city" name="city" placeholder="Enter city" 
                     class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200" required>
            </div>
            <div>
              <label for="state" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">State</label>
              <input type="text" id="state" name="state" placeholder="Enter state" 
                     class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200" required>
            </div>
            <div>
              <label for="country" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Country</label>
              <input type="text" id="country" name="country" placeholder="Enter country" 
                     class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200" required>
            </div>
            <div>
              <label for="zip_code" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Zip Code</label>
              <input type="text" id="zip_code" name="zip_code" placeholder="Enter zip code" 
                     class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200" required>
            </div>
            <div>
              <label for="phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Phone</label>
              <input type="tel" id="phone" name="phone" placeholder="Enter phone number" 
                     class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200" required>
            </div>
            <div>
              <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Email</label>
              <input type="email" id="email" name="email" placeholder="Enter email address" 
                     class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200" required>
            </div>
            <div>
              <label for="capacity" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Capacity</label>
              <input type="number" id="capacity" name="capacity" placeholder="Maximum number of members" 
                     class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200" required>
            </div>
            <div>
              <label for="latitude" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Latitude</label>
              <input type="text" id="latitude" name="latitude" placeholder="Optional - for map location" 
                     class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200">
            </div>
            <div>
              <label for="longitude" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Longitude</label>
              <input type="text" id="longitude" name="longitude" placeholder="Optional - for map location" 
                     class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200">
            </div>
          </div>
          <div class="mt-6">
            <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Description</label>
            <textarea id="description" name="description" rows="4" placeholder="Describe your gym, its facilities, and what makes it special" 
                      class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200" required></textarea>
          </div>
        </div>
        
        <!-- Images Section -->
        <div class="section-card bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-6 shadow-md animate-fade-in">
          <h2 class="text-xl font-semibold text-gray-800 dark:text-white mb-6 flex items-center">
            <div class="bg-purple-100 dark:bg-purple-900 p-2 rounded-lg mr-3">
              <i class="fas fa-images text-purple-500 dark:text-purple-400"></i>
            </div>
            Gym Images
          </h2>
          <div class="space-y-6">
            <div>
              <label for="cover_photo" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Cover Photo</label>
              <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 dark:border-gray-600 border-dashed rounded-lg">
                <div class="space-y-1 text-center">
                  <div id="cover-preview" class="hidden mb-3">
                    <img id="cover-image-preview" src="#" alt="Cover preview" class="mx-auto h-32 object-cover rounded-lg">
                  </div>
                  <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48" aria-hidden="true">
                    <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                  </svg>
                  <div class="flex text-sm text-gray-600 dark:text-gray-400">
                    <label for="cover_photo" class="relative cursor-pointer bg-white dark:bg-gray-700 rounded-md font-medium text-blue-600 dark:text-blue-400 hover:text-blue-500 focus-within:outline-none">
                      <span>Upload a file</span>
                      <input id="cover_photo" name="cover_photo" type="file" class="sr-only" accept="image/*" required onchange="previewCoverImage(this)">
                    </label>
                    <p class="pl-1">or drag and drop</p>
                  </div>
                  <p class="text-xs text-gray-500 dark:text-gray-400">
                    PNG, JPG, GIF up to 5MB (Recommended size: 1200x800px)
                  </p>
                </div>
              </div>
            </div>
            
            <div>
              <label for="gallery_images" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Gallery Images (Up to 5)</label>
              <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 dark:border-gray-600 border-dashed rounded-lg">
                <div class="space-y-1 text-center">
                  <div id="gallery-preview" class="hidden mb-3 grid grid-cols-2 md:grid-cols-5 gap-2">
                    <!-- Preview images will be inserted here -->
                  </div>
                  <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48" aria-hidden="true">
                    <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                  </svg>
                  <div class="flex text-sm text-gray-600 dark:text-gray-400">
                    <label for="gallery_images" class="relative cursor-pointer bg-white dark:bg-gray-700 rounded-md font-medium text-blue-600 dark:text-blue-400 hover:text-blue-500 focus-within:outline-none">
                      <span>Upload files</span>
                      <input id="gallery_images" name="gallery_images[]" type="file" class="sr-only" accept="image/*" multiple onchange="previewGalleryImages(this)">
                    </label>
                    <p class="pl-1">or drag and drop</p>
                  </div>
                  <p class="text-xs text-gray-500 dark:text-gray-400">
                    PNG, JPG, GIF up to 5MB each (Recommended size: 800x600px)
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Operating Hours -->
        <div class="section-card bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-6 shadow-md animate-fade-in">
          <h2 class="text-xl font-semibold text-gray-800 dark:text-white mb-6 flex items-center">
            <div class="bg-green-100 dark:bg-green-900 p-2 rounded-lg mr-3">
              <i class="fas fa-clock text-green-500 dark:text-green-400"></i>
            </div>
            Operating Hours
          </h2>
          
          <div class="mb-4">
            <label class="inline-flex items-center">
              <input type="checkbox" id="same_hours_all_days" name="same_hours_all_days" class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
              <span class="ml-2 text-gray-700 dark:text-gray-300">Same hours for all days</span>
            </label>
          </div>
          
          <div id="all-days-hours" class="hidden mb-6 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
            <h3 class="text-md font-medium text-gray-800 dark:text-gray-200 mb-3">All Days</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Morning Hours</label>
                <div class="grid grid-cols-2 gap-2">
                  <div>
                    <label for="all_morning_open" class="block text-xs text-gray-500 dark:text-gray-400">Open</label>
                    <input type="time" id="all_morning_open" name="all_morning_open" 
                           class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200">
                  </div>
                  <div>
                    <label for="all_morning_close" class="block text-xs text-gray-500 dark:text-gray-400">Close</label>
                    <input type="time" id="all_morning_close" name="all_morning_close" 
                           class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200">
                  </div>
                </div>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Evening Hours</label>
                <div class="grid grid-cols-2 gap-2">
                  <div>
                    <label for="all_evening_open" class="block text-xs text-gray-500 dark:text-gray-400">Open</label>
                    <input type="time" id="all_evening_open" name="all_evening_open" 
                           class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200">
                  </div>
                  <div>
                    <label for="all_evening_close" class="block text-xs text-gray-500 dark:text-gray-400">Close</label>
                    <input type="time" id="all_evening_close" name="all_evening_close" 
                           class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200">
                  </div>
                </div>
              </div>
            </div>
          </div>
          
          <div id="individual-days-hours">
            <?php
            $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            foreach ($days as $day) {
                $day_lower = strtolower($day);
            ?>
            <div class="mb-6 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
              <h3 class="text-md font-medium text-gray-800 dark:text-gray-200 mb-3"><?php echo $day; ?></h3>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Morning Hours</label>
                  <div class="grid grid-cols-2 gap-2">
                    <div>
                      <label for="<?php echo $day_lower; ?>_morning_open" class="block text-xs text-gray-500 dark:text-gray-400">Open</label>
                      <input type="time" id="<?php echo $day_lower; ?>_morning_open" name="hours[<?php echo $day; ?>][morning_open]" 
                             class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200">
                    </div>
                    <div>
                      <label for="<?php echo $day_lower; ?>_morning_close" class="block text-xs text-gray-500 dark:text-gray-400">Close</label>
                      <input type="time" id="<?php echo $day_lower; ?>_morning_close" name="hours[<?php echo $day; ?>][morning_close]" 
                             class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200">
                    </div>
                  </div>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Evening Hours</label>
                  <div class="grid grid-cols-2 gap-2">
                    <div>
                      <label for="<?php echo $day_lower; ?>_evening_open" class="block text-xs text-gray-500 dark:text-gray-400">Open</label>
                      <input type="time" id="<?php echo $day_lower; ?>_evening_open" name="hours[<?php echo $day; ?>][evening_open]" 
                             class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200">
                    </div>
                    <div>
                      <label for="<?php echo $day_lower; ?>_evening_close" class="block text-xs text-gray-500 dark:text-gray-400">Close</label>
                      <input type="time" id="<?php echo $day_lower; ?>_evening_close" name="hours[<?php echo $day; ?>][evening_close]" 
                             class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200">
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <?php } ?>
          </div>
        </div>
        
        <!-- Amenities Section -->
        <div class="section-card bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-6 shadow-md animate-fade-in">
          <h2 class="text-xl font-semibold text-gray-800 dark:text-white mb-6 flex items-center">
            <div class="bg-yellow-100 dark:bg-yellow-900 p-2 rounded-lg mr-3">
              <i class="fas fa-dumbbell text-yellow-500 dark:text-yellow-400"></i>
            </div>
            Amenities & Facilities
          </h2>
          
          <?php if (!empty($amenitiesByCategory)): ?>
            <?php foreach ($amenitiesByCategory as $category => $amenities): ?>
              <div class="mb-6">
                <h3 class="text-md font-medium text-gray-800 dark:text-gray-200 mb-3"><?php echo htmlspecialchars($category); ?></h3>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                  <?php foreach ($amenities as $amenity): ?>
                    <label class="inline-flex items-center p-3 border border-gray-200 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                      <input type="checkbox" name="amenities[]" value="<?php echo $amenity['id']; ?>" 
                             class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                      <span class="ml-2 text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($amenity['name']); ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="text-gray-500 dark:text-gray-400 italic mb-4">No predefined amenities available. You can add custom equipment below.</div>
          <?php endif; ?>
          
          <div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
            <h3 class="text-md font-medium text-gray-800 dark:text-gray-200 mb-3">Custom Equipment</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Add equipment that's not listed above</p>
            
            <div id="equipment-container">
              <div class="equipment-item mb-4 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Category</label>
                    <select name="equipment[0][category]" class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200">
                      <option value="">Select Category</option>
                      <?php foreach ($equipmentCategories as $category): ?>
                        <option value="<?php echo htmlspecialchars($category); ?>"><?php echo htmlspecialchars($category); ?></option>
                      <?php endforeach; ?>
                      <option value="Other">Other</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name</label>
                    <input type="text" name="equipment[0][name]" placeholder="Equipment name" 
                           class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200">
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Quantity</label>
                    <input type="number" name="equipment[0][quantity]" placeholder="Quantity" min="1" 
                           class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200">
                  </div>
                </div>
              </div>
            </div>
            
            <button type="button" id="add-equipment" class="mt-2 inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
              <i class="fas fa-plus mr-2"></i> Add More Equipment
            </button>
          </div>
        </div>
        
        <!-- Membership Plans -->
        <div class="section-card bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-6 shadow-md animate-fade-in">
          <h2 class="text-xl font-semibold text-gray-800 dark:text-white mb-6 flex items-center">
            <div class="bg-indigo-100 dark:bg-indigo-900 p-2 rounded-lg mr-3">
              <i class="fas fa-id-card text-indigo-500 dark:text-indigo-400"></i>
            </div>
            Membership Plans
          </h2>
          
          <div id="plans-container">
            <div class="plan-item mb-6 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Plan Name</label>
                  <input type="text" name="plans[0][name]" placeholder="e.g., Basic Monthly" 
                         class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200" required>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Price (₹)</label>
                  <input type="number" name="plans[0][price]" placeholder="Plan price" min="0" step="0.01" 
                         class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200" required>
                </div>
              </div>
              
              <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Tier</label>
                  <select name="plans[0][tier]" class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200" required>
                    <option value="">Select Tier</option>
                    <option value="Tier 1">Tier 1</option>
                    <option value="Tier 2">Tier 2</option>
                    <option value="Tier 3">Tier 3</option>
                  </select>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Duration</label>
                  <select name="plans[0][duration]" class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200" required>
                    <option value="">Select Duration</option>
                    <option value="Daily">Daily</option>
                    <option value="Weekly">Weekly</option>
                    <option value="Monthly">Monthly</option>
                    <option value="Quarterly">Quarterly</option>
                    <option value="Half Yearly">Half Yearly</option>
                    <option value="Yearly">Yearly</option>
                  </select>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Plan Type</label>
                  <input type="text" name="plans[0][type]" placeholder="e.g., Standard, Premium" 
                         class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200" required>
                </div>
              </div>
              
              <div class="grid grid-cols-1 gap-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Inclusions</label>
                  <textarea name="plans[0][inclusions]" rows="2" placeholder="What's included in this plan" 
                            class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200"></textarea>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Best For</label>
                  <input type="text" name="plans[0][best_for]" placeholder="e.g., Beginners, Regular gym-goers" 
                         class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200" required>
                </div>
              </div>
            </div>
          </div>
          
          <button type="button" id="add-plan" class="mt-2 inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
            <i class="fas fa-plus mr-2"></i> Add Another Plan
          </button>
        </div>
        
        <!-- Cancellation and Rescheduling Policies -->
        <div class="section-card bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-6 shadow-md animate-fade-in">
          <h2 class="text-xl font-semibold text-gray-800 dark:text-white mb-6 flex items-center">
            <div class="bg-red-100 dark:bg-red-900 p-2 rounded-lg mr-3">
              <i class="fas fa-calendar-times text-red-500 dark:text-red-400"></i>
            </div>
            Cancellation & Rescheduling Policies
          </h2>
          
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div>
              <label for="cancellation_hours" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Cancellation Hours</label>
              <input type="number" id="cancellation_hours" name="cancellation_hours" value="4" min="1" max="72" 
                     class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200">
              <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Hours before scheduled time when cancellation is allowed</p>
            </div>
            
            <div>
              <label for="reschedule_hours" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Reschedule Hours</label>
              <input type="number" id="reschedule_hours" name="reschedule_hours" value="2" min="1" max="48" 
                     class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200">
              <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Hours before scheduled time when rescheduling is allowed</p>
            </div>
          </div>
          
          <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
              <label for="cancellation_fee" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Cancellation Fee (₹)</label>
              <input type="number" id="cancellation_fee" name="cancellation_fee" value="200.00" min="0" step="0.01" 
                     class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200">
            </div>
            
            <div>
              <label for="reschedule_fee" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Reschedule Fee (₹)</label>
              <input type="number" id="reschedule_fee" name="reschedule_fee" value="100.00" min="0" step="0.01" 
                     class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200">
            </div>
            
            <div>
              <label for="late_fee" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Late Fee (₹)</label>
              <input type="number" id="late_fee" name="late_fee" value="300.00" min="0" step="0.01" 
                     class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200">
            </div>
          </div>
        </div>
        
        <!-- Additional Notes -->
        <div class="section-card bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-6 shadow-md animate-fade-in">
          <h2 class="text-xl font-semibold text-gray-800 dark:text-white mb-6 flex items-center">
            <div class="bg-blue-100 dark:bg-blue-900 p-2 rounded-lg mr-3">
              <i class="fas fa-sticky-note text-blue-500 dark:text-blue-400"></i>
            </div>
            Additional Notes
          </h2>
          
          <div>
            <label for="additional_notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Notes</label>
            <textarea id="additional_notes" name="additional_notes" rows="4" placeholder="Any additional information you'd like to share about your gym" 
                      class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200"></textarea>
          </div>
        </div>
        
        <!-- Submit Button -->
        <div class="flex justify-center mt-8">
          <button type="submit" class="bg-yellow-500 hover:bg-yellow-400 text-black font-bold py-3 px-8 rounded-full shadow-lg transform transition-all duration-300 hover:scale-105 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-opacity-50">
            <i class="fas fa-plus-circle mr-2"></i> Add Gym
          </button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
  
  <!-- Footer -->
  <footer class="bg-gray-800 text-white py-8 mt-16">
    <div class="container mx-auto px-4">
      <div class="flex flex-col md:flex-row justify-between items-center">
        <div class="mb-4 md:mb-0">
          <h3 class="text-xl font-bold">Fitness Hub</h3>
          <p class="text-gray-400 mt-1">Your partner in fitness management</p>
        </div>
        <div class="flex space-x-6">
          <a href="../about-us.php" class="text-gray-400 hover:text-white transition-colors duration-300">
            <i class="fas fa-info-circle mr-1"></i> About Us
          </a>
          <a href="../contact.php" class="text-gray-400 hover:text-white transition-colors duration-300">
            <i class="fas fa-envelope mr-1"></i> Contact
          </a>
          <a href="../privacy.php" class="text-gray-400 hover:text-white transition-colors duration-300">
            <i class="fas fa-shield-alt mr-1"></i> Privacy
          </a>
        </div>
      </div>
      <div class="mt-8 pt-6 border-t border-gray-700 text-center text-gray-400 text-sm">
        &copy; <?php echo date('Y'); ?> Fitness Hub. All rights reserved.
      </div>
    </div>
  </footer>

  <script>
    // Form progress tracking
    function updateProgress() {
      const requiredFields = document.querySelectorAll('input[required], select[required], textarea[required]');
      const totalRequired = requiredFields.length;
      let filledCount = 0;
      
      requiredFields.forEach(field => {
        if (field.value.trim() !== '') {
          filledCount++;
        }
      });
      
      const progressPercent = Math.round((filledCount / totalRequired) * 100);
      document.getElementById('progress-bar').style.width = `${progressPercent}%`;
      document.getElementById('progress-text').textContent = `${progressPercent}% Complete`;
    }
    
    // Initialize progress on page load and update on input changes
    document.addEventListener('DOMContentLoaded', function() {
      updateProgress();
      
      const formInputs = document.querySelectorAll('input, select, textarea');
      formInputs.forEach(input => {
        input.addEventListener('change', updateProgress);
        input.addEventListener('keyup', updateProgress);
      });
      
      // Operating hours toggle
      const sameHoursCheckbox = document.getElementById('same_hours_all_days');
      const allDaysHours = document.getElementById('all-days-hours');
      const individualDaysHours = document.getElementById('individual-days-hours');
      
      sameHoursCheckbox.addEventListener('change', function() {
        if (this.checked) {
          allDaysHours.classList.remove('hidden');
          individualDaysHours.classList.add('hidden');
        } else {
          allDaysHours.classList.add('hidden');
          individualDaysHours.classList.remove('hidden');
        }
      });
      
      // Copy all days hours to individual days
      const allMorningOpen = document.getElementById('all_morning_open');
      const allMorningClose = document.getElementById('all_morning_close');
      const allEveningOpen = document.getElementById('all_evening_open');
      const allEveningClose = document.getElementById('all_evening_close');
      
      [allMorningOpen, allMorningClose, allEveningOpen, allEveningClose].forEach(input => {
        input.addEventListener('change', function() {
          if (sameHoursCheckbox.checked) {
            const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            const inputId = this.id.replace('all_', '');
            
            days.forEach(day => {
              document.getElementById(`${day}_${inputId}`).value = this.value;
            });
          }
        });
      });
      
      // Add more equipment
      let equipmentCount = 1;
      document.getElementById('add-equipment').addEventListener('click', function() {
        const container = document.getElementById('equipment-container');
        const newItem = document.createElement('div');
        newItem.className = 'equipment-item mb-4 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg';
        newItem.innerHTML = `
          <div class="flex justify-between items-center mb-2">
            <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">Equipment ${equipmentCount + 1}</h4>
            <button type="button" class="remove-equipment text-red-500 hover:text-red-700">
              <i class="fas fa-times"></i> Remove
            </button>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Category</label>
              <select name="equipment[${equipmentCount}][category]" class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200">
                <option value="">Select Category</option>
                ${Array.from(document.querySelectorAll('select[name="equipment[0][category]"] option')).map(opt => 
                  `<option value="${opt.value}">${opt.textContent}</option>`
                ).join('')}
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name</label>
              <input type="text" name="equipment[${equipmentCount}][name]" placeholder="Equipment name" 
                     class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200">
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Quantity</label>
              <input type="number" name="equipment[${equipmentCount}][quantity]" placeholder="Quantity" min="1" 
                     class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200">
            </div>
          </div>
        `;
        container.appendChild(newItem);
        equipmentCount++;
        
        // Add event listener to the remove button
        newItem.querySelector('.remove-equipment').addEventListener('click', function() {
          container.removeChild(newItem);
        });
      });
      
      // Add more plans
      let planCount = 1;
      document.getElementById('add-plan').addEventListener('click', function() {
        const container = document.getElementById('plans-container');
        const newItem = document.createElement('div');
        newItem.className = 'plan-item mb-6 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg';
        newItem.innerHTML = `
          <div class="flex justify-between items-center mb-4">
            <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">Plan ${planCount + 1}</h4>
            <button type="button" class="remove-plan text-red-500 hover:text-red-700">
              <i class="fas fa-times"></i> Remove
            </button>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Plan Name</label>
              <input type="text" name="plans[${planCount}][name]" placeholder="e.g., Basic Monthly" 
                     class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200" required>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Price (₹)</label>
              <input type="number" name="plans[${planCount}][price]" placeholder="Plan price" min="0" step="0.01" 
                     class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200" required>
            </div>
          </div>
          
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Tier</label>
              <select name="plans[${planCount}][tier]" class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200" required>
                <option value="">Select Tier</option>
                <option value="Tier 1">Tier 1</option>
                <option value="Tier 2">Tier 2</option>
                <option value="Tier 3">Tier 3</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Duration</label>
              <select name="plans[${planCount}][duration]" class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200" required>
                <option value="">Select Duration</option>
                <option value="Daily">Daily</option>
                <option value="Weekly">Weekly</option>
                <option value="Monthly">Monthly</option>
                <option value="Quarterly">Quarterly</option>
                <option value="Half Yearly">Half Yearly</option>
                <option value="Yearly">Yearly</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Plan Type</label>
              <input type="text" name="plans[${planCount}][type]" placeholder="e.g., Standard, Premium" 
                     class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200" required>
            </div>
          </div>
          
          <div class="grid grid-cols-1 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Inclusions</label>
              <textarea name="plans[${planCount}][inclusions]" rows="2" placeholder="What's included in this plan" 
                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200"></textarea>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Best For</label>
              <input type="text" name="plans[${planCount}][best_for]" placeholder="e.g., Beginners, Regular gym-goers" 
                     class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200" required>
            </div>
          </div>
        `;
        container.appendChild(newItem);
        planCount++;
        
        // Add event listener to the remove button
        newItem.querySelector('.remove-plan').addEventListener('click', function() {
          container.removeChild(newItem);
          updateProgress();
        });
        
        // Update progress when new required fields are added
        updateProgress();
        
        // Add event listeners to new inputs
        const newInputs = newItem.querySelectorAll('input, select, textarea');
        newInputs.forEach(input => {
          input.addEventListener('change', updateProgress);
          input.addEventListener('keyup', updateProgress);
        });
      });
    });
    
    // Preview cover image
    function previewCoverImage(input) {
      const preview = document.getElementById('cover-preview');
      const image = document.getElementById('cover-image-preview');
      
      if (input.files && input.files[0]) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
          image.src = e.target.result;
          preview.classList.remove('hidden');
        }
        
        reader.readAsDataURL(input.files[0]);
      } else {
        preview.classList.add('hidden');
      }
    }
    
    // Preview gallery images
    function previewGalleryImages(input) {
      const preview = document.getElementById('gallery-preview');
      preview.innerHTML = '';
      
      if (input.files && input.files.length > 0) {
        preview.classList.remove('hidden');
        
        // Limit to 5 images
        const filesToPreview = Array.from(input.files).slice(0, 5);
        
        filesToPreview.forEach((file, index) => {
          const reader = new FileReader();
          
          reader.onload = function(e) {
            const imgContainer = document.createElement('div');
            imgContainer.className = 'relative';
            
            const img = document.createElement('img');
            img.src = e.target.result;
            img.className = 'h-24 w-full object-cover rounded-lg';
            img.alt = `Gallery image ${index + 1}`;
            
            imgContainer.appendChild(img);
            preview.appendChild(imgContainer);
          }
          
          reader.readAsDataURL(file);
        });
        
        if (input.files.length > 5) {
          const message = document.createElement('p');
          message.className = 'text-xs text-yellow-600 mt-2';
          message.textContent = 'Note: Only the first 5 images will be uploaded.';
          preview.appendChild(message);
        }
      } else {
        preview.classList.add('hidden');
      }
    }
  </script>
</body>
</html>




