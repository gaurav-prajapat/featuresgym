<?php
require_once 'config/database.php';

// Initialize GymDatabase connection
$GymDatabase = new GymDatabase();
$db = $GymDatabase->getConnection();

$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
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
$min_price = $_GET['min_price'] ?? '';
$max_price = $_GET['max_price'] ?? '';
if (!$city && $userCity) {
    $city = $userCity;
}

// Count total gyms for pagination
$countSql = "
    SELECT COUNT(DISTINCT g.gym_id)
    FROM gyms g 
    JOIN gym_membership_plans gmp ON g.gym_id = gmp.gym_id
    WHERE g.status = 'active'";

$countParams = [];

if ($search) {
    $countSql .= " AND (g.name LIKE ? OR g.description LIKE ? OR g.city LIKE ?)";
    $countParams[] = "%$search%";
    $countParams[] = "%$search%";
    $countParams[] = "%$search%";
}
if ($city) {
    $countSql .= " AND g.city = ?";
    $countParams[] = $city;
}
if (!empty($amenities)) {
    foreach ($amenities as $amenity) {
        $countSql .= " AND JSON_CONTAINS(g.amenities, ?)";
        $countParams[] = json_encode((int)$amenity); // Convert to integer and then to JSON
    }
}
if ($min_price !== '') {
    $countSql .= " AND gmp.price >= ?";
    $countParams[] = $min_price;
}
if ($max_price !== '') {
    $countSql .= " AND gmp.price <= ?";
    $countParams[] = $max_price;
}

$countStmt = $db->prepare($countSql);
$countStmt->execute($countParams);
$total_gyms = $countStmt->fetchColumn();
$total_pages = ceil($total_gyms / $limit);

// Fetch distinct cities for filter
$cityStmt = $db->query("SELECT DISTINCT city FROM gyms WHERE status = 'active'");
$cities = $cityStmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch all amenities for filter options
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

// Create a mapping of amenity IDs to names for JavaScript
$amenityMap = [];
foreach ($amenityOptions as $amenity) {
    $amenityMap[$amenity['id']] = [
        'name' => $amenity['name'],
        'category' => $amenity['category']
    ];
}

include 'includes/navbar.php';
?>
<div class="min-h-screen bg-gradient-to-b from-gray-900 to-black py-12">

    <div class="pt-24 pb-12">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Page Header -->
            <div class="text-center mb-12">
                <h1 class="text-4xl font-bold text-white mb-4">Find Your Perfect Gym</h1>
                <p class="text-gray-300 text-lg max-w-3xl mx-auto">
                    Discover top-rated fitness centers in your area with the amenities you need to achieve your fitness
                    goals.
                </p>
            </div>

            <!-- Include the search and filter sidebar -->
            <?php include 'search-and-filter.php'; ?>

            <!-- Main Content Area -->
            <div class="mt-24 relative">
                <!-- Loading Indicator -->
                <div id="loading-indicator"
                    class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                    <div class="bg-white p-5 rounded-lg flex flex-col items-center">
                        <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-yellow-400 mb-4">
                        </div>
                        <p class="text-gray-800 font-semibold">Loading gyms...</p>
                    </div>
                </div>

                <!-- Results Count -->
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                    <h2 class="text-2xl font-bold text-white" id="results-count">
                        Showing <?= min($total_gyms, $limit) ?> of <?= $total_gyms ?> gyms
                    </h2>
                    <div
                        class="flex flex-col sm:flex-row items-start sm:items-center space-y-3 sm:space-y-0 sm:space-x-4 w-full sm:w-auto">
                        <button id="refresh-gyms"
                            class="bg-blue-500 text-white px-4 py-2 rounded-full hover:bg-blue-600 transition-all duration-300 flex items-center">
                            <i class="fas fa-sync-alt mr-2"></i>Refresh
                        </button>
                        <select id="sort-order"
                            class="bg-gray-800 text-white border border-gray-700 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-yellow-400 w-full sm:w-auto">
                            <option value="name_asc">Name (A-Z)</option>
                            <option value="name_desc">Name (Z-A)</option>
                            <option value="price_asc">Price (Low to High)</option>
                            <option value="price_desc">Price (High to Low)</option>
                            <option value="rating_desc">Rating (High to Low)</option>
                        </select>
                    </div>
                </div>

                <!-- Gyms Grid -->
                <div id="gyms-container" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                    <!-- Gym cards will be loaded here -->
                    <div class="col-span-full text-center py-12">
                        <div
                            class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-yellow-400 mx-auto mb-4">
                        </div>
                        <p class="text-gray-300 text-lg">Loading gyms...</p>
                    </div>
                </div>

                <!-- Pagination -->
                <div id="pagination-container" class="mt-12 flex justify-center overflow-x-auto py-2">
                    <!-- Pagination will be loaded here -->
                </div>
            </div>

        </div>
    </div>

    <!-- Gym Details Modal -->
    <div id="gym-details-modal"
        class="hidden fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50 p-2 sm:p-4 overflow-y-auto">
        <div class="bg-gray-800 rounded-xl max-w-4xl w-full max-h-[90vh] overflow-y-auto my-4">
            <div class="relative">
                <div id="modal-content" class="p-4 sm:p-6">
                    <!-- Modal content will be loaded here -->
                    <div class="animate-pulse">
                        <div class="h-48 sm:h-64 bg-gray-700 rounded-lg mb-4"></div>
                        <div class="h-8 bg-gray-700 rounded w-3/4 mb-4"></div>
                        <div class="h-4 bg-gray-700 rounded w-1/2 mb-6"></div>
                        <div class="h-4 bg-gray-700 rounded w-full mb-2"></div>
                        <div class="h-4 bg-gray-700 rounded w-full mb-2"></div>
                        <div class="h-4 bg-gray-700 rounded w-3/4 mb-6"></div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="h-8 bg-gray-700 rounded"></div>
                            <div class="h-8 bg-gray-700 rounded"></div>
                        </div>
                    </div>
                </div>
                <button id="close-modal"
                    class="absolute top-2 right-2 sm:top-4 sm:right-4 text-gray-400 hover:text-white p-2 bg-gray-800 bg-opacity-75 rounded-full">
                    <i class="fas fa-times text-xl sm:text-2xl"></i>
                </button>
            </div>
        </div>
    </div>

    <?php include('includes/footer.php'); ?>
</div>

<!-- Add this hidden div to store amenity data for JavaScript -->
<div id="amenities-data" class="hidden" data-amenities='<?php echo json_encode($amenityMap); ?>'></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    // Global amenities map to store ID to name mapping
    let amenitiesMap = {};
    
    document.addEventListener('DOMContentLoaded', function () {
        // Initialize amenities map from the data attribute
        const amenitiesData = document.getElementById('amenities-data');
        if (amenitiesData) {
            try {
                amenitiesMap = JSON.parse(amenitiesData.getAttribute('data-amenities'));
                console.log('Amenities map initialized:', amenitiesMap);
            } catch (e) {
                console.error('Error parsing amenities data:', e);
            }
        }
        
        // Initial load of gyms
        loadGyms();

        // Set up event listeners
        document.getElementById('refresh-gyms').addEventListener('click', loadGyms);
        document.getElementById('sort-order').addEventListener('change', loadGyms);
        document.getElementById('close-modal').addEventListener('click', closeGymModal);

        // Set up search input event listener
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            let debounceTimer;
            searchInput.addEventListener('input', function () {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    updateUrlParam('search', this.value);
                    loadGyms();
                }, 500);
            });
        }
        
        // Set up filter form submission
        const filterForm = document.getElementById('filter-form');
        if (filterForm) {
            filterForm.addEventListener('submit', function (e) {
                e.preventDefault();
                applyFilters();
            });
        }

        // Set up city filter change event
        const cityFilter = document.getElementById('cityFilter');
        if (cityFilter) {
            cityFilter.addEventListener('change', function () {
                updateUrlParam('city', this.value);
                loadGyms();
            });
        }
        
        // Handle window resize for responsive adjustments
        window.addEventListener('resize', handleWindowResize);
    });
    
    function loadGyms() {
    const loadingIndicator = document.getElementById('loading-indicator');
    if (loadingIndicator) loadingIndicator.classList.remove('hidden');
    
    // Get current URL parameters
    const urlParams = new URLSearchParams(window.location.search);
    const page = urlParams.get('page') || 1;
    const search = urlParams.get('search') || '';
    const city = urlParams.get('city') || '';
    const minPrice = urlParams.get('min_price') || '';
    const maxPrice = urlParams.get('max_price') || '';
    const openNow = urlParams.get('open_now') === '1';
    const sortOrder = document.getElementById('sort-order')?.value || 'name_asc';
    
    // Get amenities (multiple values possible)
    const amenities = urlParams.getAll('amenities[]');
    
    // Build query string
    let queryString = `page=${page}&sort=${sortOrder}`;
    if (search) queryString += `&search=${encodeURIComponent(search)}`;
    if (city) queryString += `&city=${encodeURIComponent(city)}`;
    if (minPrice) queryString += `&min_price=${minPrice}`;
    if (maxPrice) queryString += `&max_price=${maxPrice}`;
    if (openNow) queryString += `&open_now=1`;
    
    // Add amenities to query string
    amenities.forEach(amenity => {
        queryString += `&amenities[]=${encodeURIComponent(amenity)}`;
    });
    
    // Debug: Log the request URL
    console.log(`Fetching gyms with: get_gyms.php?${queryString}`);
    
    fetch(`get_gyms.php?${queryString}`)
        .then(response => {
            // Check if response is OK
            if (!response.ok) {
                throw new Error(`HTTP error! Status: ${response.status}`);
            }
            
            // Check content type to ensure it's JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                // Try to get the text response for debugging
                return response.text().then(text => {
                    console.error('Server returned non-JSON response:', text);
                    throw new Error('Server returned non-JSON response');
                });
            }
            
            return response.json();
        })
        .then(data => {
            console.log('Received data:', data);
            
            // Check if there's an error in the response
            if (data.error) {
                throw new Error(data.message || 'Error loading gyms');
            }
            
            // Filter by open now if needed
            if (openNow) {
                const originalCount = data.gyms.length;
                data.gyms = data.gyms.filter(gym => checkIfGymIsOpen(gym.gym_id, gym.operating_hours));
                
                // Update pagination info if we're filtering client-side
                if (data.gyms.length !== originalCount) {
                    data.pagination.total_records = data.gyms.length;
                    data.pagination.total_pages = Math.ceil(data.gyms.length / data.pagination.limit);
                    if (data.pagination.current_page > data.pagination.total_pages && data.pagination.total_pages > 0) {
                        data.pagination.current_page = data.pagination.total_pages;
                    }
                }
            }
            
            updateGymsDisplay(data);
            if (loadingIndicator) loadingIndicator.classList.add('hidden');
        })
        .catch(error => {
            console.error('Error fetching gyms:', error);
            if (loadingIndicator) loadingIndicator.classList.add('hidden');
            
            const gymsContainer = document.getElementById('gyms-container');
            if (gymsContainer) {
                gymsContainer.innerHTML = `
                    <div class="col-span-full text-center py-12">
                        <i class="fas fa-exclamation-triangle text-yellow-500 text-4xl mb-4"></i>
                        <p class="text-gray-300 text-lg">Failed to load gyms: ${error.message}</p>
                        <button onclick="loadGyms()" class="mt-4 bg-yellow-500 text-black px-6 py-2 rounded-full hover:bg-yellow-400">
                            Retry
                        </button>
                    </div>
                `;
            }
        });
}

    function updateUrlParam(param, value) {
        const urlParams = new URLSearchParams(window.location.search);
        if (value) {
            urlParams.set(param, value);
        } else {
            urlParams.delete(param);
        }
        window.history.replaceState({}, '', `${window.location.pathname}?${urlParams.toString()}`);
    }

    function updateGymsDisplay(data) {
        const { gyms, pagination } = data;
        const gymsContainer = document.getElementById('gyms-container');
        const paginationContainer = document.getElementById('pagination-container');
        const resultsCount = document.getElementById('results-count');

        // Store pagination data for responsive updates
        window.lastPaginationData = pagination;

        // Update results count
        resultsCount.textContent = `Showing ${Math.min(gyms.length, pagination.limit)} of ${pagination.total_records} gyms`;

        // Clear existing content
        gymsContainer.innerHTML = '';

        if (gyms.length === 0) {
            gymsContainer.innerHTML = `
                <div class="col-span-full text-center py-12">
                    <i class="fas fa-search text-yellow-500 text-4xl mb-4"></i>
                    <p class="text-gray-300 text-lg">No gyms found matching your criteria.</p>
                    <button onclick="resetFilters()" class="mt-4 bg-yellow-500 text-black px-6 py-2 rounded-full hover:bg-yellow-400">
                        Reset Filters
                    </button>
                </div>
            `;
            return;
        }

        // Add each gym card
        gyms.forEach(gym => {
            const card = createGymCard(gym);
            gymsContainer.appendChild(card);
        });

        // Update pagination
        updatePagination(paginationContainer, pagination);
    }

    function getCurrentOperatingHours(operatingHours) {
        if (!operatingHours || operatingHours.length === 0) {
            return 'Hours not available';
        }

        // Get current day
        const currentDay = new Date().toLocaleDateString('en-US', { weekday: 'long' });

        // First check for specific day
        let todayHours = operatingHours.filter(hours =>
            hours.day === currentDay
        );

        // If no specific day found, check for "Daily" hours
        if (todayHours.length === 0) {
            todayHours = operatingHours.filter(hours =>
                hours.day === 'Daily'
            );
        }

        // If no hours found for today, return closed
        if (todayHours.length === 0) {
            return 'Closed today';
        }

        // Format the hours
        const hours = todayHours[0];
        return `${formatTime(hours.morning_open_time)} - ${formatTime(hours.morning_close_time)}, 
        ${formatTime(hours.evening_open_time)} - ${formatTime(hours.evening_close_time)}`;
    }

    // In the createGymCard function, update the price display logic:

function createGymCard(gym) {
    const card = document.createElement('div');
    card.className = 'bg-gray-800 rounded-xl overflow-hidden shadow-lg transform transition-all duration-300 hover:scale-[1.02] hover:shadow-xl';

    // Format price display - Updated logic to handle monthly plans
    let priceDisplay = 'Contact for pricing';
    let pricePeriod = '/day';
    
    if (gym.daily_price && parseFloat(gym.daily_price) > 0) {
        // If daily price exists, use it
        priceDisplay = `${parseFloat(gym.daily_price).toFixed(2)}`;
    } else if (gym.monthly_price && parseFloat(gym.monthly_price) > 0) {
        // If no daily price but monthly price exists, use monthly price
        priceDisplay = `${parseFloat(gym.monthly_price).toFixed(2)}`;
        pricePeriod = '/month';
    }

    // Format rating display
    const rating = parseFloat(gym.avg_rating) || 0;
    const ratingStars = generateRatingStars(rating);

    // Check if gym is open now
    const isOpen = checkIfGymIsOpen(gym.gym_id, gym.operating_hours);
    const openStatusClass = isOpen ? 'bg-green-500 text-white' : 'bg-red-500 text-white';
    const openStatusText = isOpen ? 'Open Now' : 'Closed';

    // Get today's hours
    const todayHours = getCurrentOperatingHours(gym.operating_hours);

    card.innerHTML = `
    <div class="relative h-48 overflow-hidden">
       <img src="${gym.cover_photo ? 'gym/uploads/gym_images/' + gym.cover_photo : 'assets/images/gym-placeholder.jpg'}" 
     alt="${escapeHtml(gym.name)}" 
     class="w-full h-full object-cover transition-transform duration-500 hover:scale-110">

        <div class="absolute top-0 left-0 flex space-x-2 m-2">
            <div class="${openStatusClass} px-3 py-1 rounded-full text-xs font-bold flex items-center">
                <span class="w-2 h-2 rounded-full ${isOpen ? 'bg-white' : 'bg-white'} mr-1"></span>
                ${openStatusText}
            </div>
            ${gym.is_featured ? `
                <div class="bg-yellow-500 text-black px-3 py-1 rounded-full text-xs font-bold">
                    Featured
                </div>
            ` : ''}
        </div>
    </div>
    <div class="p-6">
        <div class="flex justify-between items-start mb-2">
            <h3 class="text-xl font-bold text-white">${escapeHtml(gym.name)}</h3>
            <div class="bg-gray-700 rounded-full px-3 py-1 text-sm text-yellow-400 font-medium">
                ${priceDisplay}${pricePeriod}
            </div>
        </div>
        
        <div class="flex items-center mb-4">
            <div class="text-yellow-400 mr-2">${ratingStars}</div>
            <span class="text-gray-400 text-sm">(${gym.review_count || 0} reviews)</span>
        </div>
        
        <p class="text-gray-300 mb-4 line-clamp-2">${escapeHtml(gym.description || 'No description available.')}</p>
        
        <div class="flex items-center text-gray-400 mb-2">
            <i class="fas fa-map-marker-alt mr-2"></i>
            <span>${escapeHtml(gym.city)}, ${escapeHtml(gym.state)}</span>
        </div>
        
        <div class="flex items-center text-gray-400 mb-4">
            <i class="fas fa-clock mr-2"></i>
            <span>${todayHours}</span>
        </div>
        
        <div class="flex flex-wrap gap-2 mb-4">
            ${renderAmenities(gym.amenities)}
        </div>
        
        <div class="flex justify-between items-center mt-6">
            <button onclick="viewGymDetails(${gym.gym_id})" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-full transition-colors duration-300">
                <i class="fas fa-info-circle mr-2"></i>Details
            </button>
            <a href="gym-profile.php?id=${gym.gym_id}" class="bg-yellow-500 hover:bg-yellow-400 text-black px-4 py-2 rounded-full transition-colors duration-300">
                <i class="fas fa-dumbbell mr-2"></i>Visit Gym
            </a>
        </div>
    </div>
    `;

    return card;
}

    // Updated function to render amenities from IDs
    // Updated function to render amenities from IDs
function renderAmenities(amenitiesJson) {
    let amenitiesHtml = '';

    try {
        // Parse amenities if it's a string
        let amenityIds = [];
        
        if (typeof amenitiesJson === 'string') {
            amenityIds = JSON.parse(amenitiesJson);
        } else if (Array.isArray(amenitiesJson)) {
            amenityIds = amenitiesJson;
        }
        
        // Limit to 3 amenities for display
        const displayAmenities = Array.isArray(amenityIds) ? amenityIds.slice(0, 3) : [];
        
        displayAmenities.forEach(amenityId => {
            // Get amenity name from our global map
            let amenityName = 'Unknown';
            let amenityIcon = '<i class="fas fa-check"></i>';
            
            if (amenitiesMap[amenityId]) {
                amenityName = amenitiesMap[amenityId].name;
                amenityIcon = getAmenityIcon(amenityName);
            }
            
            amenitiesHtml += `
                <span class="bg-gray-700 text-gray-300 px-2 py-1 rounded-full text-xs">
                    ${amenityIcon} ${capitalizeFirstLetter(amenityName)}
                </span>
            `;
        });

        // If there are more amenities, add a +X more tag
        if (Array.isArray(amenityIds) && amenityIds.length > 3) {
            const moreCount = amenityIds.length - 3;
            amenitiesHtml += `
                <span class="bg-gray-700 text-gray-300 px-2 py-1 rounded-full text-xs">
                    +${moreCount} more
                </span>
            `;
        }
    } catch (e) {
        console.error('Error parsing amenities:', e);
    }

    return amenitiesHtml || '<span class="text-gray-500 text-xs">No amenities listed</span>';
}

    // Updated function to render full amenities from IDs
    function renderFullAmenities(amenitiesJson) {
        let amenitiesHtml = '';

        try {
            // Parse amenities if it's a string
            const amenityIds = typeof amenitiesJson === 'string' ? JSON.parse(amenitiesJson) : amenitiesJson;

            if (Array.isArray(amenityIds) && amenityIds.length > 0) {
                amenityIds.forEach(amenityId => {
                    // Get amenity name from our global map
                    let amenityName = 'Unknown';
                    let amenityIcon = '<i class="fas fa-check"></i>';
                    
                    if (amenitiesMap[amenityId]) {
                        amenityName = amenitiesMap[amenityId].name;
                        amenityIcon = getAmenityIcon(amenityName);
                    }
                    
                    amenitiesHtml += `
                        <span class="bg-gray-700 text-gray-300 px-3 py-1 rounded-full text-sm">
                            ${amenityIcon} ${capitalizeFirstLetter(amenityName)}
                        </span>
                    `;
                });
            } else {
                amenitiesHtml = '<span class="text-gray-500">No amenities listed</span>';
            }
        } catch (e) {
            console.error('Error parsing amenities:', e);
            amenitiesHtml = '<span class="text-gray-500">Error displaying amenities</span>';
        }

        return amenitiesHtml;
    }

    function timeToMinutes(timeStr) {
        try {
            const [hours, minutes, seconds] = timeStr.split(':').map(Number);
            return hours * 60 + minutes;
        } catch (e) {
            console.error('Error converting time to minutes:', e);
            return 0;
        }
    }

    // Function to check if a gym is currently open
    function checkIfGymIsOpen(gymId, operatingHours) {
        try {
            // If no operating hours data, assume closed
            if (!operatingHours || !Array.isArray(operatingHours) || operatingHours.length === 0) {
                return false;
            }
            
            // Get current day and time
            const now = new Date();
            const currentDay = now.toLocaleDateString('en-US', { weekday: 'long' });
            const currentTime = now.getHours() * 60 + now.getMinutes(); // Current time in minutes
            
            // Find applicable operating hours
            // First check for specific day
            let todayHours = operatingHours.filter(hours => 
                hours.day && hours.day.toLowerCase() === currentDay.toLowerCase()
            );
            
            // If no specific day found, check for "Daily" hours
            if (todayHours.length === 0) {
                todayHours = operatingHours.filter(hours => 
                    hours.day && hours.day.toLowerCase() === 'daily'
                );
            }
            
            // If no hours found for today, gym is closed
            if (todayHours.length === 0) return false;
            
            // Check if current time falls within any of the operating periods
            return todayHours.some(hours => {
                // Make sure all required fields exist
                if (!hours.morning_open_time || !hours.morning_close_time || 
                    !hours.evening_open_time || !hours.evening_close_time) {
                    return false;
                }
                
                // Convert times to minutes for easier comparison
                const morningOpen = timeToMinutes(hours.morning_open_time);
                const morningClose = timeToMinutes(hours.morning_close_time);
                const eveningOpen = timeToMinutes(hours.evening_open_time);
                const eveningClose = timeToMinutes(hours.evening_close_time);
                
                // Check if gym is open in the morning session
                const openInMorning = morningOpen <= currentTime && currentTime <= morningClose;
                
                // Check if gym is open in the evening session
                const openInEvening = eveningOpen <= currentTime && currentTime <= eveningClose;
                
                return openInMorning || openInEvening;
            });
        } catch (e) {
            console.error('Error checking gym hours:', e);
            return false; // Default to closed if there's an error
        }
    }

    function generateRatingStars(rating) {
        let starsHtml = '';
        const fullStars = Math.floor(rating);
        const halfStar = rating % 1 >= 0.5;
        const emptyStars = 5 - fullStars - (halfStar ? 1 : 0);

        // Add full stars
        for (let i = 0; i < fullStars; i++) {
            starsHtml += '<i class="fas fa-star"></i>';
        }

        // Add half star if needed
        if (halfStar) {
            starsHtml += '<i class="fas fa-star-half-alt"></i>';
        }

        // Add empty stars
        for (let i = 0; i < emptyStars; i++) {
            starsHtml += '<i class="far fa-star"></i>';
        }

        return starsHtml;
    }

    function getAmenityIcon(amenity) {
        const amenityIcons = {
            'pool': '<i class="fas fa-swimming-pool"></i>',
            'sauna': '<i class="fas fa-hot-tub"></i>',
            'cardio': '<i class="fas fa-heartbeat"></i>',
            'weights': '<i class="fas fa-dumbbell"></i>',
            'yoga': '<i class="fas fa-om"></i>',
            'locker': '<i class="fas fa-lock"></i>',
            'shower': '<i class="fas fa-shower"></i>',
            'wifi': '<i class="fas fa-wifi"></i>',
            'parking': '<i class="fas fa-parking"></i>',
            'trainer': '<i class="fas fa-user-friends"></i>',
            'basketball': '<i class="fas fa-basketball-ball"></i>',
            'tennis': '<i class="fas fa-table-tennis"></i>'
        };

        // Check if the amenity name (lowercase) exists in our icons map
        const amenityLower = typeof amenity === 'string' ? amenity.toLowerCase() : '';
        return amenityIcons[amenityLower] || '<i class="fas fa-check"></i>';
    }

    function updatePagination(container, pagination) {
        const { current_page, total_pages } = pagination;

        container.innerHTML = '';

        if (total_pages <= 1) return;

        const nav = document.createElement('nav');
        nav.className = 'flex items-center space-x-2 sm:space-x-4';

        // Previous button
        if (current_page > 1) {
            const prevLink = document.createElement('a');
            prevLink.href = `?page=${current_page - 1}${getFilterQueryString()}`;
            prevLink.className = 'bg-gray-700 text-white px-3 sm:px-6 py-2 sm:py-3 rounded-full font-bold hover:bg-gray-600 transform hover:scale-105 transition-all duration-300 text-sm sm:text-base';
            prevLink.innerHTML = '<i class="fas fa-chevron-left mr-1 sm:mr-2"></i><span class="hidden sm:inline">Previous</span>';
            prevLink.addEventListener('click', function (e) {
                e.preventDefault();
                updateUrlParam('page', current_page - 1);
                loadGyms();
            });
            nav.appendChild(prevLink);
        }

        // Page numbers - show fewer on mobile
        const isMobile = window.innerWidth < 640;
        const range = isMobile ? 0 : 1; // On mobile, only show current page

        for (let i = Math.max(1, current_page - range); i <= Math.min(total_pages, current_page + range); i++) {
            const pageLink = document.createElement('a');
            pageLink.href = `?page=${i}${getFilterQueryString()}`;
            pageLink.className = `${i == current_page
                ? 'bg-yellow-400 text-black'
                : 'bg-gray-700 text-white hover:bg-gray-600'} 
        px-3 sm:px-6 py-2 sm:py-3 rounded-full font-bold transform hover:scale-105 transition-all duration-300 text-sm sm:text-base`;
            pageLink.textContent = i;
            pageLink.addEventListener('click', function (e) {
                e.preventDefault();
                updateUrlParam('page', i);
                loadGyms();
            });
            nav.appendChild(pageLink);
        }

        // If we're not showing all pages, add ellipses
        if (current_page - range > 1) {
            const ellipsis = document.createElement('span');
            ellipsis.className = 'text-gray-400 px-2';
            ellipsis.textContent = '...';
            nav.insertBefore(ellipsis, nav.children[1]);
        }

        if (current_page + range < total_pages) {
            const ellipsis = document.createElement('span');
            ellipsis.className = 'text-gray-400 px-2';
            ellipsis.textContent = '...';
            nav.appendChild(ellipsis);
        }

        // Next button
        if (current_page < total_pages) {
            const nextLink = document.createElement('a');
            nextLink.href = `?page=${current_page + 1}${getFilterQueryString()}`;
            nextLink.className = 'bg-gray-700 text-white px-3 sm:px-6 py-2 sm:py-3 rounded-full font-bold hover:bg-gray-600 transform hover:scale-105 transition-all duration-300 text-sm sm:text-base';
            nextLink.innerHTML = '<span class="hidden sm:inline">Next</span><i class="fas fa-chevron-right ml-1 sm:ml-2"></i>';
            nextLink.addEventListener('click', function (e) {
                e.preventDefault();
                updateUrlParam('page', current_page + 1);
                loadGyms();
            });
            nav.appendChild(nextLink);
        }

        container.appendChild(nav);
    }

    function getFilterQueryString() {
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.delete('page'); // Remove page parameter as we'll add it separately

        const queryString = urlParams.toString();
        return queryString ? `&${queryString}` : '';
    }

    function resetFilters() {
        window.location.href = 'all-gyms.php';
    }

    function viewGymDetails(gymId) {
        const modal = document.getElementById('gym-details-modal');
        const modalContent = document.getElementById('modal-content');

        // Show modal with loading state
        modal.classList.remove('hidden');
        modalContent.innerHTML = `
        <div class="animate-pulse">
            <div class="h-64 bg-gray-700 rounded-lg mb-4"></div>
            <div class="h-8 bg-gray-700 rounded w-3/4 mb-4"></div>
            <div class="h-4 bg-gray-700 rounded w-1/2 mb-6"></div>
            <div class="h-4 bg-gray-700 rounded w-full mb-2"></div>
            <div class="h-4 bg-gray-700 rounded w-full mb-2"></div>
            <div class="h-4 bg-gray-700 rounded w-3/4 mb-6"></div>
            <div class="grid grid-cols-2 gap-4">
                <div class="h-8 bg-gray-700 rounded"></div>
                <div class="h-8 bg-gray-700 rounded"></div>
            </div>
        </div>
    `;

        // Fetch gym details
        fetch(`./get_gym_details.php?id=${gymId}`)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    throw new Error(data.error);
                }

                const gym = data.gym;
                const plans = data.membership_plans;
                const reviews = data.reviews;

                // Format rating display
                const rating = parseFloat(gym.avg_rating) || 0;
                const ratingStars = generateRatingStars(rating);

                // Check if gym is open now
                const isOpen = checkIfGymIsOpen(gym.gym_id, gym.operating_hours);
                const openStatusClass = isOpen ? 'bg-green-500 text-white' : 'bg-red-500 text-white';
                const openStatusText = isOpen ? 'Open Now' : 'Closed';

                // Render modal content
                modalContent.innerHTML = `
                <div class="relative h-64 overflow-hidden rounded-t-lg">
                    <img src="${gym.image_url || 'assets/images/gym-placeholder.jpg'}" 
                        alt="${escapeHtml(gym.name)}" 
                        class="w-full h-full object-cover">
                    <div class="absolute top-0 left-0 m-2">
                        <div class="${openStatusClass} px-3 py-1 rounded-full text-xs font-bold flex items-center">
                            <span class="w-2 h-2 rounded-full ${isOpen ? 'bg-white' : 'bg-white'} mr-1"></span>
                            ${openStatusText}
                        </div>
                    </div>
                </div>
                
                <div class="p-6">
                    <div class="flex justify-between items-start mb-2">
                        <h2 class="text-2xl font-bold text-white">${escapeHtml(gym.name)}</h2>
                        <div class="flex items-center">
                            <div class="text-yellow-400 mr-2">${ratingStars}</div>
                            <span class="text-gray-400 text-sm">(${gym.review_count || 0})</span>
                        </div>
                    </div>
                    
                    <div class="flex items-center text-gray-400 mb-4">
                        <i class="fas fa-map-marker-alt mr-2"></i>
                        <span>${escapeHtml(gym.address)}, ${escapeHtml(gym.city)}, ${escapeHtml(gym.state)} ${escapeHtml(gym.zip_code)}</span>
                    </div>
                    
                    <div class="mb-6">
                        <h3 class="text-lg font-semibold text-white mb-2">About</h3>
                        <p class="text-gray-300">${escapeHtml(gym.description || 'No description available.')}</p>
                    </div>
                    
                    <div class="mb-6">
                        <h3 class="text-lg font-semibold text-white mb-2">Hours of Operation</h3>
                        ${renderOpeningHours(gym.operating_hours)}
                    </div>
                    
                    <div class="mb-6">
                        <h3 class="text-lg font-semibold text-white mb-2">Amenities</h3>
                        <div class="flex flex-wrap gap-2">
                            ${renderFullAmenities(gym.amenities)}
                        </div>
                    </div>
                    
                    <div class="mb-6">
                        <h3 class="text-lg font-semibold text-white mb-2">Membership Plans</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            ${renderMembershipPlans(plans)}
                        </div>
                    </div>
                    
                    <div class="mb-6">
                        <h3 class="text-lg font-semibold text-white mb-2">Recent Reviews</h3>
                        ${renderReviews(reviews)}
                    </div>
                    
                    <div class="flex flex-col sm:flex-row justify-between items-center mt-8 gap-3">
                        <button onclick="closeGymModal()" class="w-full sm:w-auto bg-gray-700 hover:bg-gray-600 text-white px-6 py-3 rounded-full transition-colors duration-300">
                            Close
                        </button>
                        <a href="gym-profile.php?id=${gym.gym_id}" class="w-full sm:w-auto text-center bg-yellow-500 hover:bg-yellow-400 text-black px-6 py-3 rounded-full font-bold transition-colors duration-300">
                            Visit Gym Page
                        </a>
                    </div>
                </div>
            `;
            })
            .catch(error => {
                console.error('Error fetching gym details:', error);
                modalContent.innerHTML = `
                <div class="p-6 text-center">
                    <i class="fas fa-exclamation-triangle text-yellow-500 text-4xl mb-4"></i>
                    <p class="text-gray-300 text-lg mb-6">Failed to load gym details. Please try again.</p>
                    <button onclick="closeGymModal()" class="bg-gray-700 hover:bg-gray-600 text-white px-6 py-3 rounded-full transition-colors duration-300">
                        Close
                    </button>
                </div>
            `;
            });
    }

    function renderMembershipPlans(plans) {
        if (!plans || plans.length === 0) {
            return '<p class="text-gray-500 col-span-full">No membership plans available</p>';
        }

        let plansHtml = '';

        plans.forEach(plan => {
            plansHtml += `
            <div class="bg-gray-700 rounded-lg p-4">
                <h4 class="text-white font-semibold mb-2">${escapeHtml(plan.tier)}</h4>
                <p class="text-yellow-400 font-bold mb-2">${parseFloat(plan.price).toFixed(2)} / ${plan.duration}</p>
                <p class="text-gray-300 text-sm mb-3">${escapeHtml(plan.inclusions || 'No details available')}</p>
                <a href="membership.php?plan_id=${plan.plan_id}" class="block text-center bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-full text-sm transition-colors duration-300">
                    Select Plan
                </a>
            </div>
        `;
        });

        return plansHtml;
    }

    function renderReviews(reviews) {
        if (!reviews || reviews.length === 0) {
            return `
            <div class="text-center py-4">
                <p class="text-gray-500">No reviews yet</p>
            </div>
        `;
        }

        // Limit to 3 most recent reviews
        const displayReviews = reviews.slice(0, 3);
        let reviewsHtml = '';

        displayReviews.forEach(review => {
            const reviewRating = parseFloat(review.rating);
            const reviewStars = generateRatingStars(reviewRating);
            const reviewDate = new Date(review.created_at).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });

            reviewsHtml += `
            <div class="border-b border-gray-700 pb-4 mb-4 last:border-0 last:mb-0 last:pb-0">
                                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-start mb-2 gap-2">
                    <div>
                        <div class="text-white font-medium">${escapeHtml(review.user_name || 'Anonymous')}</div>
                        <div class="text-gray-400 text-sm">${reviewDate}</div>
                    </div>
                    <div class="text-yellow-400">${reviewStars}</div>
                </div>
                <p class="text-gray-300">${escapeHtml(review.comment)}</p>
            </div>
        `;
        });

        // Add "View All" link if there are more reviews
        if (reviews.length > 3) {
            reviewsHtml += `
            <div class="text-center mt-4">
                <a href="gym-profile.php?id=${reviews[0].gym_id}#reviews" class="text-blue-400 hover:text-blue-300">
                    View all ${reviews.length} reviews
                </a>
            </div>
        `;
        }

        return reviewsHtml;
    }

    function renderOpeningHours(operatingHours) {
        try {
            if (!operatingHours || operatingHours.length === 0) {
                return '<p class="text-gray-500">No opening hours available</p>';
            }

            // Get current day
            const currentDay = new Date().toLocaleDateString('en-US', { weekday: 'long' });

            // Days of the week in order
            const daysOfWeek = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

            // Check if there's a "Daily" schedule
            const dailyHours = operatingHours.find(hours => hours.day === 'Daily');

            let hoursHtml = '<div class="grid grid-cols-1 sm:grid-cols-2 gap-2">';

            if (dailyHours) {
                // If there's a daily schedule, show it for all days
                hoursHtml += `
                <div class="bg-gray-700 rounded p-2 col-span-full">
                    <div class="flex justify-between items-center">
                        <span class="text-white font-medium">Daily</span>
                        <span class="text-gray-300">
                            ${formatTime(dailyHours.morning_open_time)} - ${formatTime(dailyHours.morning_close_time)}, 
                            ${formatTime(dailyHours.evening_open_time)} - ${formatTime(dailyHours.evening_close_time)}
                        </span>
                    </div>
                </div>
            `;
            } else {
                // Otherwise show individual days
                daysOfWeek.forEach(day => {
                    const dayHours = operatingHours.find(hours => hours.day === day);
                    const isToday = day === currentDay;
                    const highlightClass = isToday ? 'border-l-4 border-yellow-400 pl-2' : '';

                    let timeDisplay = 'Closed';
                    if (dayHours) {
                        timeDisplay = `
                        ${formatTime(dayHours.morning_open_time)} - ${formatTime(dayHours.morning_close_time)}, 
                        ${formatTime(dayHours.evening_open_time)} - ${formatTime(dayHours.evening_close_time)}
                    `;
                    }

                    hoursHtml += `
                    <div class="bg-gray-700 rounded p-2 ${highlightClass}">
                        <div class="flex justify-between items-center">
                            <span class="text-white capitalize font-medium">${day}${isToday ? ' <span class="text-yellow-400">(Today)</span>' : ''}</span>
                            <span class="text-gray-300">${timeDisplay}</span>
                        </div>
                    </div>
                `;
                });
            }

            hoursHtml += '</div>';
            return hoursHtml;
        } catch (e) {
            console.error('Error rendering opening hours:', e);
            return '<p class="text-gray-500">Error displaying opening hours</p>';
        }
    }

    // Helper function to format time
    function formatTime(timeStr) {
        if (!timeStr) return 'N/A';

        try {
            // Convert from 24-hour format to 12-hour format
            const [hours, minutes] = timeStr.split(':');
            const hour = parseInt(hours, 10);
            const ampm = hour >= 12 ? 'PM' : 'AM';
            const hour12 = hour % 12 || 12;
            return `${hour12}:${minutes} ${ampm}`;
        } catch (e) {
            return timeStr;
        }
    }

    function closeGymModal() {
        document.getElementById('gym-details-modal').classList.add('hidden');
    }

    function escapeHtml(unsafe) {
        if (unsafe === null || unsafe === undefined) return '';
        return String(unsafe)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function capitalizeFirstLetter(string) {
        if (!string) return '';
        return string.charAt(0).toUpperCase() + string.slice(1);
    }

    // Close modal when clicking outside of it
    document.getElementById('gym-details-modal').addEventListener('click', function (e) {
        if (e.target === this) {
            closeGymModal();
        }
    });

    // Handle live search from search-and-filter.php
    function liveSearch(query) {
        updateUrlParam('search', query);
        loadGyms();
    }

    // Handle window resize for responsive adjustments
    window.addEventListener('resize', handleWindowResize);

    function handleWindowResize() {
        // Update pagination if it exists
        const paginationContainer = document.getElementById('pagination-container');
        if (paginationContainer && paginationContainer.children.length > 0) {
            const urlParams = new URLSearchParams(window.location.search);
            const currentPage = parseInt(urlParams.get('page') || '1');

            // Only update if we have pagination data
            if (window.lastPaginationData) {
                window.lastPaginationData.current_page = currentPage;
                updatePagination(paginationContainer, window.lastPaginationData);
            }
        }
    }

    // Function to apply all filters
    function applyFilters() {
        const searchInput = document.getElementById('searchInput').value;
        const cityFilter = document.getElementById('cityFilter').value;
        const minPrice = document.getElementById('minPrice').value;
        const maxPrice = document.getElementById('maxPrice').value;
        const openNow = document.getElementById('openNow')?.checked ? '1' : '';

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
</script>
</body>
</html>