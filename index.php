<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Error handling for production
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Include necessary files
require_once 'config/database.php';

try {
    // Database connection
    $db = new GymDatabase();
    $conn = $db->getConnection();

    // Fetch system settings
    $settingsQuery = "SELECT setting_key, setting_value FROM system_settings";
    $settingsStmt = $conn->prepare($settingsQuery);
    $settingsStmt->execute();
    $systemSettings = [];
    
    while ($row = $settingsStmt->fetch(PDO::FETCH_ASSOC)) {
        $systemSettings[$row['setting_key']] = $row['setting_value'];
    }
    
    // Set default values if settings are not found
    $siteName = $systemSettings['site_name'] ?? 'FlexFit';
    $siteTagline = $systemSettings['site_tagline'] ?? 'Find Your Perfect Fitness Experience';
    $logoPath = $systemSettings['logo_path'] ?? 'assets/images/logo.png';
    $faviconPath = $systemSettings['favicon_path'] ?? 'assets/images/favicon.ico';
    $primaryColor = $systemSettings['primary_color'] ?? '#EAB308'; // Default yellow-500
    $secondaryColor = $systemSettings['secondary_color'] ?? '#2563EB'; // Default blue-600

    // Include navbar after database connection to ensure any user data is available
    include 'includes/navbar.php';

    // Fetch featured gyms with limit and proper caching
    $limit = isset($_GET['all']) ? null : 6;
    $cacheKey = 'featured_gyms_' . ($limit ?: 'all');
    $featured_gyms = null;

    // Check if we have cached data (implement your caching mechanism here)
    // For example: $featured_gyms = getCache($cacheKey);

    if (!$featured_gyms) {
        // Optimized query with proper indexing
        $sql = "
            SELECT g.gym_id, g.name, g.city, g.state, g.cover_photo, g.is_open, 
                   COUNT(r.id) as review_count, 
                   ROUND(AVG(r.rating), 1) as avg_rating
            FROM gyms g
            LEFT JOIN reviews r ON g.gym_id = r.gym_id
            WHERE g.status = 'active'
            GROUP BY g.gym_id
            ORDER BY g.is_featured DESC, avg_rating DESC, review_count DESC
        ";

        if ($limit) {
            $sql .= " LIMIT " . intval($limit); // Ensure limit is an integer
        }

        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $featured_gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cache the results (implement your caching mechanism here)
        // For example: setCache($cacheKey, $featured_gyms, 3600); // Cache for 1 hour
    }

    // Prepare data for view
    foreach ($featured_gyms as &$gym) {
        // Ensure numeric values are properly formatted
        $gym['avg_rating'] = $gym['avg_rating'] ? number_format((float) $gym['avg_rating'], 1) : '0.0';
        $gym['review_count'] = (int) $gym['review_count'];

        // Ensure image path exists, use default if not
        if (empty($gym['cover_photo']) || !file_exists("./gym/uploads/gym_images/" . $gym['cover_photo'])) {
            $gym['cover_photo'] = 'default-gym.jpg';
        }
    }
    unset($gym); // Break the reference
    ?>
    
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($siteName) ?> - <?= htmlspecialchars($siteTagline) ?></title>
        <link rel="icon" href="<?= htmlspecialchars($faviconPath) ?>" type="image/x-icon">
        <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
        <style>
            .animate-fade-in {
                animation: fadeIn 1s ease-out;
            }

            .animate-fade-in-delay {
                animation: fadeIn 1s ease-out 0.3s both;
            }

            .animate-fade-in-delay-2 {
                animation: fadeIn 1s ease-out 0.6s both;
            }

            @keyframes fadeIn {
                from {
                    opacity: 0;
                    transform: translateY(20px);
                }

                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
        </style>
    </head>
    <body class="bg-gray-900 text-white">

    <!-- Section Header -->
    <section class="hero-section relative">
        <div class="container mx-auto px-4 py-20 relative ">
            <div class="max-w-3xl mx-auto text-center">
                <h1 class="text-4xl md:text-5xl lg:text-6xl font-bold text-white mb-6 leading-tight">
                    Find Your Perfect <span class="text-yellow-400">Fitness</span> Experience
                </h1>
                <p class="text-xl text-gray-300 mb-8">
                    Discover top-rated gyms, personalized workout plans, and a community of fitness enthusiasts
                </p>

                <!-- Search Form -->
                <form action="all-gyms.php" method="GET" class="max-w-2xl mx-auto mb-8 px-4">
                    <div class="flex flex-col md:flex-row items-center gap-4">
                        <div class="w-full md:flex-1 relative">
                            <div class="absolute inset-y-0 left-4 flex items-center pointer-events-none">
                                <i class="fas fa-search text-gray-300"></i>
                            </div>
                            <input type="text" name="search" placeholder="Search gyms by name or location"
                                style='padding-left: 2.5rem;'
                                class="w-full py-3 rounded-full bg-white bg-opacity-20 backdrop-blur-md border border-white border-opacity-30 text-white placeholder-gray-300 focus:outline-none focus:ring-2 focus:ring-yellow-400">
                        </div>
                        <button type="submit"
                            class="w-full md:w-auto px-8 py-3 bg-yellow-500 hover:bg-yellow-400 text-black font-bold rounded-full transition duration-300 transform hover:scale-105">
                            Find Gyms
                        </button>
                    </div>
                </form>

                <!-- CTA Buttons -->
                <div class="flex flex-col sm:flex-row justify-center gap-4 mt-8">
                    <?php if (!isset($_SESSION['user_id'])): ?>
                        <a href="register.php"
                            class="px-8 py-4 bg-blue-600 hover:bg-blue-500 text-white font-bold rounded-full transition duration-300 transform hover:scale-105">
                            <i class="fas fa-user-plus mr-2"></i> Join Now
                        </a>
                    <?php endif; ?>
                    <a href="all-gyms.php"
                        class="px-8 py-4 bg-transparent hover:bg-white hover:bg-opacity-10 text-white font-bold rounded-full border-2 border-white transition duration-300">
                        <i class="fas fa-dumbbell mr-2"></i> Explore Gyms
                    </a>
                </div>

                <!-- Stats -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-6 mt-16">
                    <div class="bg-black bg-opacity-50 backdrop-blur-md rounded-xl p-4">
                        <div class="text-3xl font-bold text-yellow-400 mb-1">
                            <?php
                            try {
                                $stmt = $conn->query("SELECT COUNT(*) FROM gyms WHERE status = 'active'");
                                echo number_format($stmt->fetchColumn());
                            } catch (PDOException $e) {
                                echo "500+";
                            }
                            ?>
                        </div>
                        <div class="text-gray-300">Gyms</div>
                    </div>
                    <div class="bg-black bg-opacity-50 backdrop-blur-md rounded-xl p-4">
                        <div class="text-3xl font-bold text-yellow-400 mb-1">
                            <?php
                            try {
                                $stmt = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'member'");
                                echo number_format($stmt->fetchColumn());
                            } catch (PDOException $e) {
                                echo "10K+";
                            }
                            ?>
                        </div>
                        <div class="text-gray-300">Members</div>
                    </div>
                    <div class="bg-black bg-opacity-50 backdrop-blur-md rounded-xl p-4">
                        <div class="text-3xl font-bold text-yellow-400 mb-1">
                            <?php
                            try {
                                $stmt = $conn->query("SELECT COUNT(*) FROM schedules WHERE status = 'completed'");
                                echo number_format($stmt->fetchColumn());
                            } catch (PDOException $e) {
                                echo "50K+";
                            }
                            ?>
                        </div>
                        <div class="text-gray-300">Workouts</div>
                    </div>
                    <div class="bg-black bg-opacity-50 backdrop-blur-md rounded-xl p-4">
                        <div class="text-3xl font-bold text-yellow-400 mb-1">
                            <?php
                            try {
                                $stmt = $conn->query("SELECT COUNT(*) FROM cities");
                                echo number_format($stmt->fetchColumn());
                            } catch (PDOException $e) {
                                echo "100+";
                            }
                            ?>
                        </div>
                        <div class="text-gray-300">Cities</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Scroll indicator -->
        <div class="absolute bottom-8 left-1/2 transform -translate-x-1/2 text-white animate-bounce">
            <i class="fas fa-chevron-down text-2xl"></i>
        </div>
    </section>

    <style>
        @media (max-width: 640px) {
            .animate-scale {
                animation: none;
            }
        }

        @media (min-width: 768px) {
            .container {
                max-width: 768px;
            }
        }

        @media (min-width: 1024px) {
            .container {
                max-width: 1024px;
            }
        }

        @media (min-width: 1280px) {
            .container {
                max-width: 1280px;
            }
        }

        .animate-fade-in {
            animation: fadeIn 1s ease-out;
        }

        .animate-fade-in-delay {
            animation: fadeIn 1s ease-out 0.3s both;
        }

        .animate-fade-in-delay-2 {
            animation: fadeIn 1s ease-out 0.6s both;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>

    <!-- Featured Gyms Section -->
    <section class="py-12 sm:py-16 md:py-20 lg:py-24 bg-gradient-to-b from-gray-900 to-black">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-3xl sm:text-4xl font-bold text-white mb-4">Featured <span class="text-primary">Gyms</span></h2>
                <p class="text-gray-300 max-w-2xl mx-auto">Discover our top-rated fitness centers with state-of-the-art equipment and expert trainers</p>
            </div>
            
            <!-- Gyms Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6 lg:gap-8">
                <?php foreach ($featured_gyms as $gym): ?>
                    <div
                        class="group bg-gray-800 rounded-xl sm:rounded-2xl overflow-hidden transform hover:scale-105 transition-all duration-300">
                        <!-- Image Container -->
                        <div class="relative h-48 sm:h-60 lg:h-72">
                            <?php
                            $image_path = "gym/uploads/gym_images/" . htmlspecialchars($gym['cover_photo']);
                            $default_image = "assets/images/gym-placeholder.jpg";
                            $image_src = file_exists($image_path) ? $image_path : $default_image;
                            ?>
                            <img src="<?= $image_src ?>" alt="<?= htmlspecialchars($gym['name']) ?>"
                                class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500">

                            <!-- Featured Badge -->
                            <div
                                class="absolute top-3 right-3 sm:top-4 sm:right-4 bg-primary text-black px-3 py-1 sm:px-4 sm:py-2 rounded-full text-sm sm:text-base font-bold">
                                Featured
                            </div>
                             <!-- Open/Closed Status Badge -->
                             <div
                                class="absolute top-3 left-3 sm:top-4 sm:left-4 <?= isset($gym['is_open']) && $gym['is_open'] ? 'bg-green-500' : 'bg-red-500' ?> text-white px-3 py-1 sm:px-4 sm:py-2 rounded-full text-sm sm:text-base font-semibold">
                                <?= isset($gym['is_open']) && $gym['is_open'] ? 'Open' : 'Closed' ?>
                            </div>
                        </div>

                        <!-- Content -->
                        <div class="p-4 sm:p-6">
                            <div class="flex justify-between items-start mb-2">
                                <h3 class="text-lg sm:text-xl font-bold text-white truncate">
                                    <?= htmlspecialchars($gym['name']) ?>
                                </h3>
                                <div class="flex items-center">
                                    <span class="text-primary mr-1"><i class="fas fa-star"></i></span>
                                    <span class="text-white font-medium"><?= $gym['avg_rating'] ?></span>
                                </div>
                            </div>

                            <div class="flex items-center text-gray-400 mb-4">
                                <i class="fas fa-map-marker-alt mr-2"></i>
                                <span class="truncate"><?= htmlspecialchars($gym['city']) ?>, <?= htmlspecialchars($gym['state']) ?></span>
                            </div>

                            <div class="flex justify-between items-center">
                                <span class="text-sm text-gray-400">
                                    <i class="fas fa-comment mr-1"></i> <?= $gym['review_count'] ?> reviews
                                </span>
                                <a href="gym-profile.php?id=<?= $gym['gym_id'] ?>"
                                    class="px-4 py-2 bg-secondary hover:bg-blue-600 text-white rounded-lg transition-colors duration-300">
                                    View Gym
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- View All Button -->
            <div class="text-center mt-12">
                <a href="all-gyms.php"
                    class="inline-block px-8 py-4 bg-primary hover:bg-yellow-400 text-black font-bold rounded-full transition duration-300 transform hover:scale-105">
                    View All Gyms <i class="fas fa-arrow-right ml-2"></i>
                </a>
            </div>
        </div>
    </section>

    <!-- How It Works Section -->
    <section class="py-16 bg-gray-900">
        <div class="container mx-auto px-4">
            <div class="text-center mb-12">
                <h2 class="text-3xl sm:text-4xl font-bold text-white mb-4">How <span class="text-primary">It Works</span></h2>
                <p class="text-gray-300 max-w-2xl mx-auto">Simple steps to start your fitness journey with FlexFit</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <!-- Step 1 -->
                <div class="bg-gray-800 rounded-xl p-6 text-center animate-fade-in">
                    <div class="w-16 h-16 bg-primary rounded-full flex items-center justify-center text-black text-2xl font-bold mx-auto mb-4">1</div>
                    <h3 class="text-xl font-bold text-white mb-3">Find a Gym</h3>
                    <p class="text-gray-300">Browse our extensive collection of gyms and fitness centers. Filter by location, amenities, and ratings to find your perfect match.</p>
                </div>

                <!-- Step 2 -->
                <div class="bg-gray-800 rounded-xl p-6 text-center animate-fade-in-delay">
                    <div class="w-16 h-16 bg-primary rounded-full flex items-center justify-center text-black text-2xl font-bold mx-auto mb-4">2</div>
                    <h3 class="text-xl font-bold text-white mb-3">Purchase a Plan</h3>
                    <p class="text-gray-300">Choose from a variety of membership plans that suit your needs and budget. Pay securely online and get instant access.</p>
                </div>

                <!-- Step 3 -->
                <div class="bg-gray-800 rounded-xl p-6 text-center animate-fade-in-delay-2">
                    <div class="w-16 h-16 bg-primary rounded-full flex items-center justify-center text-black text-2xl font-bold mx-auto mb-4">3</div>
                    <h3 class="text-xl font-bold text-white mb-3">Start Working Out</h3>
                    <p class="text-gray-300">Schedule your visits, track your progress, and enjoy premium fitness facilities. Rate your experience and help others find great gyms.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Testimonials Section -->
    <section class="py-16 bg-black">
        <div class="container mx-auto px-4">
            <div class="text-center mb-12">
                <h2 class="text-3xl sm:text-4xl font-bold text-white mb-4">What Our <span class="text-primary">Members Say</span></h2>
                <p class="text-gray-300 max-w-2xl mx-auto">Hear from our satisfied members about their fitness journey with FlexFit</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <?php
                // Fetch testimonials (reviews with high ratings)
                try {
                    $stmt = $conn->prepare("
                        SELECT r.comment, r.rating, u.username, u.profile_image, g.name as gym_name
                        FROM reviews r
                        JOIN users u ON r.user_id = u.id
                        JOIN gyms g ON r.gym_id = g.gym_id
                        WHERE r.rating >= 4 AND r.status = 'approved'
                        ORDER BY RAND()
                        LIMIT 3
                    ");
                    $stmt->execute();
                    $testimonials = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // If no testimonials found, use some default ones
                    if (empty($testimonials)) {
                        $testimonials = [
                            [
                                'username' => 'John D.',
                                'profile_image' => null,
                                'rating' => 5,
                                'comment' => 'FlexFit has completely transformed my fitness journey. The platform is so easy to use and I\'ve discovered amazing gyms in my area!',
                                'gym_name' => 'PowerFit Gym'
                            ],
                            [
                                'username' => 'Sarah M.',
                                'profile_image' => null,
                                'rating' => 5,
                                'comment' => 'I love the flexibility of being able to try different gyms. The membership plans are affordable and the booking system is seamless.',
                                'gym_name' => 'Elite Fitness Center'
                            ],
                            [
                                'username' => 'Michael T.',
                                'profile_image' => null,
                                'rating' => 4,
                                'comment' => 'As someone who travels frequently, FlexFit has been a game-changer. I can find quality gyms wherever I go and maintain my fitness routine.',
                                'gym_name' => 'Urban Strength Gym'
                            ]
                        ];
                    }

                    foreach ($testimonials as $testimonial):
                        $profile_image = !empty($testimonial['profile_image']) ? 'uploads/profile_images/' . $testimonial['profile_image'] : 'assets/images/default-avatar.png';
                        ?>
                        <div class="bg-gray-800 rounded-xl p-6 animate-fade-in">
                            <div class="flex items-center mb-4">
                                <img src="<?= htmlspecialchars($profile_image) ?>" alt="<?= htmlspecialchars($testimonial['username']) ?>" class="w-12 h-12 rounded-full object-cover mr-4">
                                <div>
                                    <h4 class="text-white font-bold"><?= htmlspecialchars($testimonial['username']) ?></h4>
                                    <div class="flex text-primary">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star <?= $i <= $testimonial['rating'] ? 'text-primary' : 'text-gray-600' ?> mr-1"></i>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>
                            <p class="text-gray-300 mb-4">"<?= htmlspecialchars($testimonial['comment']) ?>"</p>
                            <div class="text-sm text-gray-400">Member at <span class="text-primary"><?= htmlspecialchars($testimonial['gym_name']) ?></span></div>
                        </div>
                    <?php endforeach;
                } catch (PDOException $e) {
                    // If there's an error, show default testimonials
                    ?>
                    <div class="bg-gray-800 rounded-xl p-6 animate-fade-in">
                        <div class="flex items-center mb-4">
                            <img src="assets/images/default-avatar.png" alt="John D." class="w-12 h-12 rounded-full object-cover mr-4">
                            <div>
                                <h4 class="text-white font-bold">John D.</h4>
                                <div class="flex text-primary">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star text-primary mr-1"></i>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </div>
                        <p class="text-gray-300 mb-4">"FlexFit has completely transformed my fitness journey. The platform is so easy to use and I've discovered amazing gyms in my area!"</p>
                        <div class="text-sm text-gray-400">Member at <span class="text-primary">PowerFit Gym</span></div>
                    </div>
                    
                    <div class="bg-gray-800 rounded-xl p-6 animate-fade-in-delay">
                        <div class="flex items-center mb-4">
                            <img src="assets/images/default-avatar.png" alt="Sarah M." class="w-12 h-12 rounded-full object-cover mr-4">
                            <div>
                                <h4 class="text-white font-bold">Sarah M.</h4>
                                <div class="flex text-primary">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star text-primary mr-1"></i>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </div>
                        <p class="text-gray-300 mb-4">"I love the flexibility of being able to try different gyms. The membership plans are affordable and the booking system is seamless."</p>
                        <div class="text-sm text-gray-400">Member at <span class="text-primary">Elite Fitness Center</span></div>
                    </div>
                    
                    <div class="bg-gray-800 rounded-xl p-6 animate-fade-in-delay-2">
                        <div class="flex items-center mb-4">
                            <img src="assets/images/default-avatar.png" alt="Michael T." class="w-12 h-12 rounded-full object-cover mr-4">
                            <div>
                                <h4 class="text-white font-bold">Michael T.</h4>
                                <div class="flex text-primary">
                                    <?php for ($i = 1; $i <= 4; $i++): ?>
                                        <i class="fas fa-star text-primary mr-1"></i>
                                    <?php endfor; ?>
                                    <i class="fas fa-star text-gray-600 mr-1"></i>
                                </div>
                            </div>
                        </div>
                        <p class="text-gray-300 mb-4">"As someone who travels frequently, FlexFit has been a game-changer. I can find quality gyms wherever I go and maintain my fitness routine."</p>
                        <div class="text-sm text-gray-400">Member at <span class="text-primary">Urban Strength Gym</span></div>
                    </div>
                <?php } ?>
            </div>
        </div>
    </section>

    <!-- Membership Benefits -->
    <section class="py-12 sm:py-16 md:py-20 lg:py-24 bg-gradient-to-b from-black to-gray-900 relative overflow-hidden">
        <div class="absolute inset-0 bg-pattern opacity-10"></div>

        <div class="container mx-auto px-4 sm:px-6 lg:px-8 relative">
            <div class="text-center mb-8 sm:mb-12 lg:mb-16">
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold text-gray-100 mb-2 sm:mb-4">
                    Premium <span class="text-yellow-400">Benefits</span>
                </h2>
                <p class="text-base sm:text-lg lg:text-xl text-white max-w-xl sm:max-w-2xl mx-auto px-4">
                    Experience exclusive advantages designed for your fitness success
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 lg:gap-8">
                <!-- Personal Training -->
                <div
                    class="group bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-xl sm:rounded-2xl lg:rounded-3xl p-6 sm:p-7 lg:p-8 hover:bg-yellow-400 transition-all duration-500">
                    <div
                        class="h-12 w-12 sm:h-14 sm:w-14 lg:h-16 lg:w-16 bg-yellow-400 group-hover:bg-black rounded-xl sm:rounded-2xl flex items-center justify-center mb-4 sm:mb-5 lg:mb-6">
                        <i
                            class="fas fa-user-friends text-lg sm:text-xl lg:text-2xl text-black group-hover:text-yellow-400"></i>
                    </div>
                    <h3 class="text-lg sm:text-xl font-bold text-white group-hover:text-black mb-2 sm:mb-3 lg:mb-4">Personal
                        Training</h3>
                    <p class="text-sm sm:text-base text-white group-hover:text-black">One-on-one sessions with certified
                        trainers for personalized guidance</p>
                </div>

                <!-- Advanced Equipment -->
                <div
                    class="group bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-xl sm:rounded-2xl lg:rounded-3xl p-6 sm:p-7 lg:p-8 hover:bg-yellow-400 transition-all duration-500">
                    <div
                        class="h-12 w-12 sm:h-14 sm:w-14 lg:h-16 lg:w-16 bg-yellow-400 group-hover:bg-black rounded-xl sm:rounded-2xl flex items-center justify-center mb-4 sm:mb-5 lg:mb-6">
                        <i
                            class="fas fa-dumbbell text-lg sm:text-xl lg:text-2xl text-black group-hover:text-yellow-400"></i>
                    </div>
                    <h3 class="text-lg sm:text-xl font-bold text-white group-hover:text-black mb-2 sm:mb-3 lg:mb-4">Premium
                        Equipment</h3>
                    <p class="text-sm sm:text-base text-white group-hover:text-black">Access to state-of-the-art fitness
                        equipment and facilities</p>
                </div>

                <!-- Nutrition Planning -->
                <div
                    class="group bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-xl sm:rounded-2xl lg:rounded-3xl p-6 sm:p-7 lg:p-8 hover:bg-yellow-400 transition-all duration-500">
                    <div
                        class="h-12 w-12 sm:h-14 sm:w-14 lg:h-16 lg:w-16 bg-yellow-400 group-hover:bg-black rounded-xl sm:rounded-2xl flex items-center justify-center mb-4 sm:mb-5 lg:mb-6">
                        <i
                            class="fas fa-apple-alt text-lg sm:text-xl lg:text-2xl text-black group-hover:text-yellow-400"></i>
                    </div>
                    <h3 class="text-lg sm:text-xl font-bold text-white group-hover:text-black mb-2 sm:mb-3 lg:mb-4">
                        Nutrition Planning</h3>
                    <p class="text-sm sm:text-base text-white group-hover:text-black">Customized diet plans and nutritional
                        guidance for optimal results</p>
                </div>

                <!-- Fitness Classes -->
                <div
                    class="group bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-xl sm:rounded-2xl lg:rounded-3xl p-6 sm:p-7 lg:p-8 hover:bg-yellow-400 transition-all duration-500">
                    <div
                        class="h-12 w-12 sm:h-14 sm:w-14 lg:h-16 lg:w-16 bg-yellow-400 group-hover:bg-black rounded-xl sm:rounded-2xl flex items-center justify-center mb-4 sm:mb-5 lg:mb-6">
                        <i class="fas fa-users text-lg sm:text-xl lg:text-2xl text-black group-hover:text-yellow-400"></i>
                    </div>
                    <h3 class="text-lg sm:text-xl font-bold text-white group-hover:text-black mb-2 sm:mb-3 lg:mb-4">Group
                        Classes</h3>
                    <p class="text-sm sm:text-base text-white group-hover:text-black">Join energetic group sessions led by
                        expert instructors</p>
                </div>
            </div>
        </div>
    </section>

    <section class="relative py-12 sm:py-16 md:py-20 lg:py-24 bg-gradient-to-b from-gray-900 to-black overflow-hidden">
        <div class="absolute inset-0 bg-pattern opacity-10"></div>

        <div class="container mx-auto px-4 sm:px-6 lg:px-8 relative">
            <!-- Section Header -->
            <div class="text-center mb-8 sm:mb-12 lg:mb-16">
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold text-white mb-3 sm:mb-4">
                    Why Choose <span class="text-yellow-400">FitConnect</span>?
                </h2>
                <div class="w-16 sm:w-20 lg:w-24 h-1 bg-yellow-400 mx-auto"></div>
            </div>

            <!-- Features Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 sm:gap-8 lg:gap-12">
                <!-- Wide Network -->
                <div
                    class="group bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-xl sm:rounded-2xl lg:rounded-3xl p-6 sm:p-7 lg:p-8 transform hover:-translate-y-2 transition-all duration-300">
                    <div
                        class="w-14 h-14 sm:w-16 sm:h-16 lg:w-20 lg:h-20 bg-yellow-400 rounded-xl sm:rounded-2xl flex items-center justify-center mb-4 sm:mb-5 lg:mb-6 transform -rotate-6 group-hover:rotate-0 transition-transform duration-300">
                        <svg class="h-7 w-7 sm:h-8 sm:w-8 lg:h-10 lg:w-10 text-black" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
                        </svg>
                    </div>
                    <h3 class="text-xl sm:text-2xl font-bold text-white mb-3 sm:mb-4">Wide Network</h3>
                    <p class="text-sm sm:text-base text-white leading-relaxed">Access premium fitness centers across India.
                        Connect with the largest network of certified gyms and trainers.</p>
                </div>

                <!-- Flexible Plans -->
                <div
                    class="group bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-xl sm:rounded-2xl lg:rounded-3xl p-6 sm:p-7 lg:p-8 transform hover:-translate-y-2 transition-all duration-300">
                    <div
                        class="w-14 h-14 sm:w-16 sm:h-16 lg:w-20 lg:h-20 bg-yellow-400 rounded-xl sm:rounded-2xl flex items-center justify-center mb-4 sm:mb-5 lg:mb-6 transform -rotate-6 group-hover:rotate-0 transition-transform duration-300">
                        <svg class="h-7 w-7 sm:h-8 sm:w-8 lg:h-10 lg:w-10 text-black" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <h3 class="text-xl sm:text-2xl font-bold text-white mb-3 sm:mb-4">Flexible Plans</h3>
                    <p class="text-sm sm:text-base text-white leading-relaxed">Customize your fitness journey with flexible
                        membership options. Choose plans that fit your schedule and goals.</p>
                </div>

                <!-- Quality Assured -->
                <div
                    class="group bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-xl sm:rounded-2xl lg:rounded-3xl p-6 sm:p-7 lg:p-8 transform hover:-translate-y-2 transition-all duration-300">
                    <div
                        class="w-14 h-14 sm:w-16 sm:h-16 lg:w-20 lg:h-20 bg-yellow-400 rounded-xl sm:rounded-2xl flex items-center justify-center mb-4 sm:mb-5 lg:mb-6 transform -rotate-6 group-hover:rotate-0 transition-transform duration-300">
                        <svg class="h-7 w-7 sm:h-8 sm:w-8 lg:h-10 lg:w-10 text-black" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                    </div>
                    <h3 class="text-xl sm:text-2xl font-bold text-white mb-3 sm:mb-4">Quality Assured</h3>
                    <p class="text-sm sm:text-base text-white leading-relaxed">Experience fitness in verified and certified
                        facilities. Every gym meets our strict quality and safety standards.</p>
                </div>
            </div>
        </div>
    </section>

   <!-- CTA Section -->
   <section class="py-16 bg-gradient-to-b from-black to-gray-900">
        <div class="container mx-auto px-4">
            <div class="bg-gray-800 rounded-2xl p-8 md:p-12 text-center max-w-4xl mx-auto">
                <h2 class="text-3xl md:text-4xl font-bold text-white mb-6">Ready to Start Your <span class="text-primary">Fitness Journey</span>?</h2>
                <p class="text-xl text-gray-300 mb-8 max-w-2xl mx-auto">Join thousands of members who have transformed their lives with FlexFit. Get access to premium gyms and start your journey today.</p>
                <div class="flex flex-col sm:flex-row justify-center gap-4">
                    <?php if (!isset($_SESSION['user_id'])): ?>
                        <a href="register.php" class="px-8 py-4 bg-primary hover:bg-yellow-400 text-black font-bold rounded-full transition duration-300 transform hover:scale-105">
                            <i class="fas fa-user-plus mr-2"></i> Sign Up Now
                        </a>
                        <a href="login.php" class="px-8 py-4 bg-transparent hover:bg-white hover:bg-opacity-10 text-white font-bold rounded-full border-2 border-white transition duration-300">
                            <i class="fas fa-sign-in-alt mr-2"></i> Login
                        </a>
                    <?php else: ?>
                        <a href="all-gyms.php" class="px-8 py-4 bg-primary hover:bg-yellow-400 text-black font-bold rounded-full transition duration-300 transform hover:scale-105">
                            <i class="fas fa-dumbbell mr-2"></i> Explore Gyms
                        </a>
                        <a href="dashboard.php" class="px-8 py-4 bg-transparent hover:bg-white hover:bg-opacity-10 text-white font-bold rounded-full border-2 border-white transition duration-300">
                        <i class="fas fa-tachometer-alt mr-2"></i> Go to Dashboard
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
    <!-- App Features Section -->
    <section class="py-16 bg-white dark:bg-gray-800">
        <div class="container mx-auto px-4">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold mb-4">Why Choose <span class="text-yellow-500">Fitness Hub</span></h2>
                <p class="text-gray-600 dark:text-gray-400 max-w-2xl mx-auto">
                    Discover the benefits of using our platform for all your fitness needs
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
                <div
                    class="bg-white dark:bg-gray-700 rounded-xl p-6 shadow-lg text-center transform transition-all duration-300 hover:scale-105">
                    <div
                        class="w-16 h-16 mx-auto mb-4 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center">
                        <i class="fas fa-search text-2xl text-blue-600 dark:text-blue-400"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800 dark:text-white mb-3">Find Gyms Easily</h3>
                    <p class="text-gray-600 dark:text-gray-300">
                        Discover top-rated gyms in your area with detailed information and reviews
                    </p>
                </div>

                <div
                    class="bg-white dark:bg-gray-700 rounded-xl p-6 shadow-lg text-center transform transition-all duration-300 hover:scale-105">
                    <div
                        class="w-16 h-16 mx-auto mb-4 bg-green-100 dark:bg-green-900 rounded-full flex items-center justify-center">
                        <i class="fas fa-calendar-check text-2xl text-green-600 dark:text-green-400"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800 dark:text-white mb-3">Book Workouts</h3>
                    <p class="text-gray-600 dark:text-gray-300">
                        Schedule your gym sessions in advance and never miss a workout
                    </p>
                </div>

                <div
                    class="bg-white dark:bg-gray-700 rounded-xl p-6 shadow-lg text-center transform transition-all duration-300 hover:scale-105">
                    <div
                        class="w-16 h-16 mx-auto mb-4 bg-purple-100 dark:bg-purple-900 rounded-full flex items-center justify-center">
                        <i class="fas fa-trophy text-2xl text-purple-600 dark:text-purple-400"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800 dark:text-white mb-3">Join Tournaments</h3>
                    <p class="text-gray-600 dark:text-gray-300">
                        Participate in fitness competitions and challenge yourself to new heights
                    </p>
                </div>

                <div
                    class="bg-white dark:bg-gray-700 rounded-xl p-6 shadow-lg text-center transform transition-all duration-300 hover:scale-105">
                    <div
                        class="w-16 h-16 mx-auto mb-4 bg-red-100 dark:bg-red-900 rounded-full flex items-center justify-center">
                        <i class="fas fa-users text-2xl text-red-600 dark:text-red-400"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800 dark:text-white mb-3">Community</h3>
                    <p class="text-gray-600 dark:text-gray-300">
                        Connect with fitness enthusiasts, share experiences, and stay motivated
                    </p>
                </div>
            </div>

            <div class="mt-12 text-center">
                <?php if (!isset($_SESSION['user_id'])): ?>
                    <a href="register.php"
                        class="inline-block px-8 py-4 bg-yellow-500 hover:bg-yellow-400 text-black font-bold rounded-full transition duration-300 mr-4">
                        Join Now
                    </a>
                <?php endif; ?>
                <a href="about.php"
                    class="inline-block px-8 py-4 bg-blue-600 hover:bg-blue-500 text-white font-bold rounded-full transition duration-300">
                    Learn More
                </a>
            </div>
        </div>
    </section>

    <!-- Newsletter Section -->
    <section class="py-16 bg-gradient-to-r from-blue-600 to-purple-600 text-white hidden">
        <div class="container mx-auto px-4">
            <div class="max-w-3xl mx-auto text-center">
                <h2 class="text-3xl font-bold mb-4">Stay Updated with Fitness Tips</h2>
                <p class="mb-8">
                    Subscribe to our newsletter for the latest fitness trends, workout tips, and exclusive offers
                </p>

                <form action="process_newsletter.php" method="POST"
                    class="flex flex-col sm:flex-row gap-4 max-w-xl mx-auto">
                    <input type="email" name="email" placeholder="Your email address" required
                        class="flex-1 px-6 py-4 rounded-full bg-white bg-opacity-20 backdrop-blur-md border border-white border-opacity-30 text-white placeholder-gray-200 focus:outline-none focus:ring-2 focus:ring-yellow-400">
                    <button type="submit"
                        class="px-8 py-4 bg-yellow-500 hover:bg-yellow-400 text-black font-bold rounded-full transition duration-300">
                        Subscribe
                    </button>
                </form>

                <p class="text-sm mt-4 text-gray-200">
                    We respect your privacy. Unsubscribe at any time.
                </p>
            </div>
        </div>
    </section>

    <!-- Download App Section -->
    <section class="py-16 bg-gray-100 dark:bg-gray-900 hidden">
        <div class="container mx-auto px-4">
            <div class="flex flex-col lg:flex-row items-center">
                <div class="lg:w-1/2 mb-10 lg:mb-0">
                    <h2 class="text-3xl font-bold mb-4">Get Our <span class="text-yellow-500">Mobile App</span></h2>
                    <p class="text-gray-600 dark:text-gray-400 mb-6">
                        Download our mobile app to access your fitness journey on the go. Book workouts, track progress, and
                        connect with the community from anywhere.
                    </p>

                    <div class="flex flex-col sm:flex-row gap-4">
                        <a href="#" class="flex items-center bg-black text-white px-6 py-3 rounded-xl">
                            <i class="fab fa-apple text-3xl mr-3"></i>
                            <div>
                                <div class="text-xs">Download on the</div>
                                <div class="text-xl font-semibold">App Store</div>
                            </div>
                        </a>

                        <a href="#" class="flex items-center bg-black text-white px-6 py-3 rounded-xl">
                            <i class="fab fa-google-play text-3xl mr-3"></i>
                            <div>
                                <div class="text-xs">GET IT ON</div>
                                <div class="text-xl font-semibold">Google Play</div>
                            </div>
                        </a>
                    </div>
                </div>

                <div class="lg:w-1/2 flex justify-center">
                    <img src="assets/images/app-mockup.png" alt="Fitness Hub Mobile App"
                        class="max-w-full h-auto rounded-xl shadow-2xl">
                </div>
            </div>
        </div>
    </section>


    <!-- Contact Form Section -->
    <section class="py-12 sm:py-16 md:py-20 lg:py-24 bg-gradient-to-b from-gray-900 to-black relative overflow-hidden">
        <div class="absolute inset-0 bg-pattern opacity-10"></div>

        <div class="container mx-auto px-4 sm:px-6 lg:px-8 relative">
            <!-- Section Header -->
            <div class="text-center mb-8 sm:mb-12 lg:mb-16">
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold text-white mb-2 sm:mb-4">
                    Get In <span class="text-yellow-400">Touch</span>
                </h2>
                <div class="w-16 sm:w-20 lg:w-24 h-1 bg-yellow-400 mx-auto"></div>
                <p class="mt-4 text-base sm:text-lg text-white max-w-xl mx-auto">
                    Have questions? We're here to help and answer any question you might have.
                </p>
            </div>

            <!-- Form Container -->
            <div class="max-w-xl sm:max-w-2xl lg:max-w-3xl mx-auto">
                <form action="process_contact.php" method="POST" class="space-y-4 sm:space-y-6 lg:space-y-8">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6 lg:gap-8">
                        <!-- Name Field -->
                        <div class="group">
                            <label class="block text-yellow-400 text-sm sm:text-base mb-1.5 sm:mb-2">Name</label>
                            <input type="text" name="name" required class="w-full bg-gray-800 border-2 border-gray-700 rounded-lg px-3 sm:px-4 py-2 sm:py-3 text-sm sm:text-base text-white
                                      focus:border-yellow-400 focus:ring-2 focus:ring-yellow-400 focus:ring-opacity-50
                                      transition-all duration-300">
                        </div>

                        <!-- Email Field -->
                        <div class="group">
                            <label class="block text-yellow-400 text-sm sm:text-base mb-1.5 sm:mb-2">Email</label>
                            <input type="email" name="email" required class="w-full bg-gray-800 border-2 border-gray-700 rounded-lg px-3 sm:px-4 py-2 sm:py-3 text-sm sm:text-base text-white
                                      focus:border-yellow-400 focus:ring-2 focus:ring-yellow-400 focus:ring-opacity-50
                                      transition-all duration-300">
                        </div>
                    </div>

                    <!-- Phone Field -->
                    <div class="group">
                        <label class="block text-yellow-400 text-sm sm:text-base mb-1.5 sm:mb-2">Phone Number</label>
                        <input type="tel" name="phone" pattern="[0-9]{10}" class="w-full bg-gray-800 border-2 border-gray-700 rounded-lg px-3 sm:px-4 py-2 sm:py-3 text-sm sm:text-base text-white
                                  focus:border-yellow-400 focus:ring-2 focus:ring-yellow-400 focus:ring-opacity-50
                                  transition-all duration-300">
                    </div>

                    <!-- Subject Field -->
                    <div class="group">
                        <label class="block text-yellow-400 text-sm sm:text-base mb-1.5 sm:mb-2">Subject</label>
                        <select name="subject" required class="w-full bg-gray-800 border-2 border-gray-700 rounded-lg px-3 sm:px-4 py-2 sm:py-3 text-sm sm:text-base text-white
                                   focus:border-yellow-400 focus:ring-2 focus:ring-yellow-400 focus:ring-opacity-50
                                   transition-all duration-300">
                            <option value="">Select a subject</option>
                            <option value="membership">Membership Inquiry</option>
                            <option value="training">Personal Training</option>
                            <option value="facilities">Facility Information</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <!-- Message Field -->
                    <div class="group">
                        <label class="block text-yellow-400 text-sm sm:text-base mb-1.5 sm:mb-2">Message</label>
                        <textarea name="message" rows="5" required class="w-full bg-gray-800 border-2 border-gray-700 rounded-lg px-3 sm:px-4 py-2 sm:py-3 text-sm sm:text-base text-white
                                     focus:border-yellow-400 focus:ring-2 focus:ring-yellow-400 focus:ring-opacity-50
                                     transition-all duration-300 resize-none"></textarea>
                    </div>

                    <!-- CSRF Protection -->
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRFToken()) ?>">

                    <!-- Submit Button -->
                    <div class="text-center pt-4">
                        <button type="submit" class="bg-yellow-400 text-black px-8 sm:px-10 lg:px-12 py-3 sm:py-4 rounded-full 
                                   text-sm sm:text-base lg:text-lg font-bold
                                   hover:bg-yellow-500 transform hover:scale-105 
                                   transition-all duration-300 shadow-lg">
                            Send Message
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </section>


    <!-- Footer with Social Icons -->
  
<?php
 include 'includes/footer.php';
} catch (PDOException $e) {
    // Log the error
    error_log("Database error: " . $e->getMessage());
    
    // Display a user-friendly error page
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Temporarily Unavailable - <?= htmlspecialchars($siteName ?? 'FlexFit') ?></title>
        <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    </head>
    <body class="bg-gray-900 text-white min-h-screen flex items-center justify-center">
        <div class="container mx-auto px-4 text-center">
            <div class="mb-8">
                <i class="fas fa-dumbbell text-yellow-500 text-6xl"></i>
            </div>
            <h1 class="text-4xl font-bold mb-4">We're Taking a Quick Break</h1>
            <p class="text-xl text-gray-300 mb-8 max-w-2xl mx-auto">
                Our system is currently undergoing maintenance. Please check back in a few minutes.
            </p>
            <button onclick="window.location.reload()" class="px-8 py-4 bg-yellow-500 hover:bg-yellow-400 text-black font-bold rounded-full transition duration-300">
                <i class="fas fa-sync-alt mr-2"></i> Try Again
            </button>
        </div>
    </body>
    </html>
    <?php
}


/**
 * Generate a CSRF token for form security
 * 
 * @return string The CSRF token
 */
function generateCSRFToken()
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
?>