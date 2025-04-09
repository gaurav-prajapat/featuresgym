<?php

require_once 'config/database.php';
require_once 'includes/auth.php';

// Initialize GymDatabase connection
$GymDatabase = new GymDatabase();
$db = $GymDatabase->getConnection();
$auth = new Auth($db);

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 9;
$offset = ($page - 1) * $limit;

$userCity = '';
if (isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
    $query = "SELECT city FROM users WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$userId]);
    $userCity = $stmt->fetchColumn() ?: '';
}

// Search and filter parameters
$search = $_GET['search'] ?? '';
$city = $_GET['city'] ?? '';
$amenities = $_GET['amenities'] ?? [];
$min_price = $_GET['min_price'] ?? $_POST['min_price'] ?? '';  // Added min price filter
$max_price = $_GET['max_price'] ?? '';  // Added max price filter
$open_now = isset($_GET['open_now']) ? (bool)$_GET['open_now'] : false; // New filter for open now
if (!$city && $userCity) {
    $city = $userCity;
}

$sql = "
    SELECT DISTINCT g.*, 
           (SELECT AVG(rating) FROM reviews r WHERE r.gym_id = g.gym_id) as avg_rating,
           (SELECT COUNT(*) FROM reviews r WHERE r.gym_id = g.gym_id) as review_count,
           gmp.price as daily_price,
           gmp.price as daily_rate
    FROM gyms g 
    JOIN gym_membership_plans gmp ON g.gym_id = gmp.gym_id
    LEFT JOIN gym_operating_hours goh ON g.gym_id = goh.gym_id
    WHERE g.status = 'active'";


$params = [];

if ($search) {
    $sql .= " AND (g.name LIKE ? OR g.description LIKE ? OR g.city LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($city) {
    $sql .= " AND g.city = ?";
    $params[] = $city;
}
if (!empty($amenities)) {
    foreach ($amenities as $amenity) {
        $sql .= " AND JSON_CONTAINS(g.amenities, ?)";
        $params[] = '"' . $amenity . '"';
    }
}

// Add price filter if set
if ($min_price !== '') {
    $sql .= " AND gmp.price >= ?";
    $params[] = $min_price;
}

if ($max_price !== '') {
    $sql .= " AND gmp.price <= ?";
    $params[] = $max_price;
}

// Note: Open now filtering will be done client-side with JavaScript
// since it depends on current time

$sql .= " GROUP BY g.gym_id ORDER BY g.name ASC LIMIT $limit OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch distinct cities for filter
$cityStmt = $db->query("SELECT DISTINCT city FROM gyms WHERE status = 'active' ORDER BY city ASC");
$cities = $cityStmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch all amenities for filter options with IDs
$amenityQuery = "SELECT id, name, category FROM amenities WHERE availability = 1 ORDER BY category, name";
$amenityStmt = $db->query($amenityQuery);
$amenityOptions = $amenityStmt->fetchAll(PDO::FETCH_ASSOC);

// Group amenities by category
$amenitiesByCategory = [];
foreach ($amenityOptions as $amenity) {
    $category = $amenity['category'] ?: 'Other';
    if (!isset($amenitiesByCategory[$category])) {
        $amenitiesByCategory[$category] = [];
    }
    $amenitiesByCategory[$category][] = $amenity;
}

?>

<!-- Mobile Filter Toggle Button -->
<div class="md:hidden mb-4">
    <button id="mobile-filter-toggle" class="w-full bg-gray-800 text-white px-4 py-3 rounded-lg flex justify-between items-center">
        <span><i class="fas fa-filter mr-2"></i>Search & Filters</span>
        <i class="fas fa-chevron-down" id="filter-chevron"></i>
    </button>
</div>

<!-- Search and Filter Section -->
<div id="filter-container" class="mb-8 md:block hidden">
    <div class="bg-gray-800 rounded-xl p-4 sm:p-6 shadow-lg">
        <h2 class="text-xl font-bold text-white mb-4">Find Your Perfect Gym</h2>
        
        <form id="filter-form" class="space-y-6">
            <!-- Search Input -->
            <div>
                <label for="searchInput" class="block text-gray-300 mb-2">Search Gyms</label>
                <div class="relative">
                    <input type="text" 
                           id="searchInput" 
                           name="search" 
                           style='padding-left: 2.5rem;'
                           value="<?= htmlspecialchars($search) ?>"
                           class="w-full bg-gray-700 text-white border border-gray-600 rounded-lg pl-10 pr-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400 placeholder-gray-400"
                           placeholder="Search by name, location or amenities">
                    <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                </div>
            </div>
            
            <!-- Filter Options -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <!-- City Filter -->
                <div>
                    <label for="cityFilter" class="block text-gray-300 mb-2">City</label>
                    <div class="relative">
                        <select id="cityFilter" 
                                name="city" 
                                class="w-full bg-gray-700 text-white border border-gray-600 rounded-lg pl-10 pr-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400 appearance-none">
                            <option value="">All Cities</option>
                            <?php foreach ($cities as $cityOption): ?>
                                <option value="<?= htmlspecialchars($cityOption) ?>" 
                                        <?= $city === $cityOption ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cityOption) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <i class="fas fa-map-marker-alt absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        <i class="fas fa-chevron-down absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 pointer-events-none"></i>
                    </div>
                </div>
                
                <!-- Price Range -->
                <div>
                    <label class="block text-gray-300 mb-2">Price Range (₹/day)</label>
                    <div class="grid grid-cols-2 gap-2">
                        <div class="relative">
                            <input type="number" 
                                   id="minPrice" 
                                   name="min_price" 
                                   value="<?= htmlspecialchars($min_price) ?>"
                                   placeholder="Min" 
                                   min="0" style='padding-left: 2.5rem;'
                                   class="w-full bg-gray-700 text-white border border-gray-600 rounded-lg pl-9 pr-3 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
                            <i class="fas fa-rupee-sign absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        </div>
                        <div class="relative">
                            <input type="number" 
                                   id="maxPrice" 
                                   name="max_price" 
                                   value="<?= htmlspecialchars($max_price) ?>"
                                   placeholder="Max" 
                                   min="0"
                                   style='padding-left: 2.5rem;'
                                   class="w-full bg-gray-700 text-white border border-gray-600 rounded-lg pl-9 pr-3 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
                            <i class="fas fa-rupee-sign absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Open Now Filter -->
                <div>
                    <label class="block text-gray-300 mb-2">Availability</label>
                    <label class="flex items-center bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 cursor-pointer hover:bg-gray-600 transition-colors">
                        <input type="checkbox" 
                               id="openNowFilter" 
                               name="open_now" 
                               value="1" 
                               <?= $open_now ? 'checked' : '' ?>
                               class="form-checkbox h-5 w-5 text-yellow-400 rounded focus:ring-yellow-400 focus:ring-opacity-50">
                        <span class="ml-2 text-white flex items-center">
                            <i class="fas fa-door-open text-green-400 mr-2"></i>Open Now
                        </span>
                    </label>
                </div>
            </div>
            
           <!-- Amenities Filter -->
<div class="filter-section">
    <button type="button" class="filter-toggle w-full flex justify-between items-center text-left text-gray-300 font-medium py-2 sm:py-3">
        <span>Amenities</span>
        <i class="fas fa-chevron-down transition-transform duration-200"></i>
    </button>
    <div class="filter-content pt-3 space-y-4">
        <?php foreach ($amenitiesByCategory as $category => $categoryAmenities): ?>
            <div class="space-y-2">
                <h4 class="text-gray-400 text-sm font-medium"><?= htmlspecialchars(ucfirst($category)) ?></h4>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2">
                    <?php foreach ($categoryAmenities as $amenity): ?>
                        <label class="flex items-center space-x-2 bg-gray-700 p-2 rounded-lg cursor-pointer hover:bg-gray-600 transition-colors">
                            <input type="checkbox" 
                                   name="amenities[]" 
                                   value="<?= htmlspecialchars($amenity['id']) ?>"
                                   <?= in_array($amenity['id'], $amenities) ? 'checked' : '' ?>
                                   class="form-checkbox h-4 w-4 text-yellow-400 rounded focus:ring-yellow-400 focus:ring-opacity-50">
                            <span class="text-gray-300 text-sm"><?= htmlspecialchars(ucfirst($amenity['name'])) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>


            
            <!-- Filter Actions -->
            <div class="flex flex-col sm:flex-row space-y-3 sm:space-y-0 sm:space-x-4">
                <button type="submit" 
                        class="bg-yellow-500 hover:bg-yellow-400 text-black px-6 py-3 rounded-full font-bold transition-colors duration-300 w-full sm:w-auto flex justify-center items-center">
                    <i class="fas fa-filter mr-2"></i>Apply Filters
                </button>
                <button type="button" 
                        onclick="resetFilters()" 
                        class="bg-gray-700 hover:bg-gray-600 text-white px-6 py-3 rounded-full font-bold transition-colors duration-300 w-full sm:w-auto flex justify-center items-center">
                    <i class="fas fa-times mr-2"></i>Reset
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Mobile filter toggle
    const mobileFilterToggle = document.getElementById('mobile-filter-toggle');
    const filterContainer = document.getElementById('filter-container');
    const filterChevron = document.getElementById('filter-chevron');
    
    if (mobileFilterToggle && filterContainer) {
        mobileFilterToggle.addEventListener('click', function() {
            filterContainer.classList.toggle('hidden');
            filterChevron.classList.toggle('fa-chevron-down');
            filterChevron.classList.toggle('fa-chevron-up');
        });
    }
    
    // Set up filter form submission
    const filterForm = document.getElementById('filter-form');
    if (filterForm) {
        filterForm.addEventListener('submit', function(e) {
            e.preventDefault();
            applyFilters();
        });
    }
    
    // Set up search input event listener
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        let debounceTimer;
        searchInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                updateUrlParam('search', this.value);
                loadGyms();
            }, 500);
        });
    }
    
    // Set up city filter change event
    const cityFilter = document.getElementById('cityFilter');
    if (cityFilter) {
        cityFilter.addEventListener('change', function() {
            updateUrlParam('city', this.value);
            loadGyms();
        });
    }
    
    // Set up open now filter change event
    const openNowFilter = document.getElementById('openNowFilter');
    if (openNowFilter) {
        openNowFilter.addEventListener('change', function() {
            updateUrlParam('open_now', this.checked ? '1' : '');
            loadGyms();
        });
    }
    
    // Set up price filter input events
    const minPriceInput = document.getElementById('minPrice');
    const maxPriceInput = document.getElementById('maxPrice');
    
    if (minPriceInput && maxPriceInput) {
        minPriceInput.addEventListener('change', function() {
            if (parseInt(this.value) < 0) this.value = 0;
            if (maxPriceInput.value && parseInt(this.value) > parseInt(maxPriceInput.value)) {
                this.value = maxPriceInput.value;
            }
        });
        
        maxPriceInput.addEventListener('change', function() {
            if (parseInt(this.value) < 0) this.value = 0;
            if (minPriceInput.value && parseInt(this.value) < parseInt(minPriceInput.value)) {
                this.value = minPriceInput.value;
            }
        });
    }
    
    // Set up filter section toggles
    const filterToggles = document.querySelectorAll('.filter-toggle');
    filterToggles.forEach(toggle => {
        toggle.addEventListener('click', function() {
            const content = this.nextElementSibling;
            const icon = this.querySelector('i');
            
            content.classList.toggle('hidden');
            icon.classList.toggle('transform');
            icon.classList.toggle('rotate-180');
        });
    });
});

// Function to apply all filters
function applyFilters() {
    const searchInput = document.getElementById('searchInput').value;
    const cityFilter = document.getElementById('cityFilter').value;
    const minPrice = document.getElementById('minPrice').value;
    const maxPrice = document.getElementById('maxPrice').value;
    const openNow = document.getElementById('openNowFilter').checked ? '1' : '';
    
    // Get all checked amenities
    const amenityCheckboxes = document.querySelectorAll('input[name="amenities[]"]:checked');
    const amenities = Array.from(amenityCheckboxes).map(cb => cb.value);
    
    // Update URL parameters
    updateUrlParam('search', searchInput);
    updateUrlParam('city', cityFilter);
    updateUrlParam('min_price', minPrice);
    updateUrlParam('max_price', maxPrice);
    updateUrlParam('open_now', openNow);
    
    // Remove existing amenities params
    const url = new URL(window.location);
    url.searchParams.delete('amenities[]');
    
    // Add new amenities params
    amenities.forEach(amenity => {
        url.searchParams.append('amenities[]', amenity);
    });
    
    // Reset to page 1 when filtering
    url.searchParams.set('page', '1');
    
    window.history.pushState({}, '', url);
    
    // Load gyms with new filters
    loadGyms();
}

// Function to reset all filters
function resetFilters() {
    // Clear all form inputs
    document.getElementById('searchInput').value = '';
    document.getElementById('cityFilter').value = '';
    document.getElementById('minPrice').value = '';
    document.getElementById('maxPrice').value = '';
    document.getElementById('openNowFilter').checked = false;
    
    // Uncheck all amenity checkboxes
    const amenityCheckboxes = document.querySelectorAll('input[name="amenities[]"]:checked');
    amenityCheckboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    
    // Reset URL to base
    window.location.href = 'all-gyms.php';
}

// Function to update URL parameters
function updateUrlParam(param, value) {
    const url = new URL(window.location);
    
    if (value === '' || value === null) {
        url.searchParams.delete(param);
    } else {
        url.searchParams.set(param, value);
    }
    
    window.history.pushState({}, '', url);
}
</script>
