<?php
require_once 'config/database.php';
$db = new GymDatabase();
$conn = $db->getConnection();

// Get about us content from system settings
$aboutUsContent = '';
try {
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'about_us'");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $aboutUsContent = $result['setting_value'];
    }
} catch (PDOException $e) {
    // Fallback to default content if there's an error
    $aboutUsContent = '';
}

include 'includes/navbar.php';
?>

<main class="bg-gray-100 dark:bg-gray-900 text-gray-800 dark:text-gray-200 pt-20">
    <section class="about-us py-12">
        <div class="container mx-auto px-4 lg:px-16">
            <h1 class="text-4xl font-bold text-center text-gray-800 dark:text-white mb-8">About Us</h1>
            
            <?php if (!empty($aboutUsContent)): ?>
                <div class="text-lg text-gray-700 dark:text-gray-300">
                    <?php echo $aboutUsContent; ?>
                </div>
            <?php else: ?>
                <!-- Default About Us Content with Theme Support -->
                <div class="about-us-content">
                    <p class="text-lg text-gray-700 dark:text-gray-300 text-center mb-6">
                        Welcome to <strong class="text-yellow-600 dark:text-yellow-400">FeatureGym</strong> - revolutionizing the way you experience fitness with our innovative multi-gym access platform. We're on a mission to make fitness accessible, flexible, and seamless no matter where you are.
                    </p>
                    
                    <div class="section mb-8 bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                        <h2 class="text-2xl font-bold text-yellow-600 dark:text-yellow-400 mb-4">Our Story</h2>
                        <p class="text-gray-700 dark:text-gray-300">
                            Founded in 2023, FeatureGym was born from a simple observation: traditional gym memberships are too restrictive. Our founders, avid fitness enthusiasts who traveled frequently, were frustrated with having to pay for multiple gym memberships or miss workouts while away from home.
                        </p>
                        <p class="mt-3 text-gray-700 dark:text-gray-300">
                            We asked: "What if there was a single membership that gave access to quality gyms everywhere?" That question sparked the creation of FeatureGym - a platform that connects fitness enthusiasts with gyms across the country through one convenient membership.
                        </p>
                    </div>
                    
                    <div class="section mb-8 bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                        <h2 class="text-2xl font-bold text-yellow-600 dark:text-yellow-400 mb-4">Our Mission</h2>
                        <p class="text-gray-700 dark:text-gray-300">
                            At FeatureGym, we believe fitness should be accessible, convenient, and adaptable to your lifestyle. Our mission is to break down the barriers between you and your fitness goals by providing:
                        </p>
                        <ul class="list-disc pl-6 mt-3 space-y-2 text-gray-700 dark:text-gray-300">
                            <li>Seamless access to quality gyms nationwide</li>
                            <li>Flexible membership options that fit your schedule and budget</li>
                            <li>A user-friendly platform that makes finding and booking gym sessions effortless</li>
                            <li>A supportive community that encourages consistent fitness habits regardless of location</li>
                        </ul>
                    </div>
                    
                    <div class="section mb-8 bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                        <h2 class="text-2xl font-bold text-yellow-600 dark:text-yellow-400 mb-4">What Sets Us Apart</h2>
                        
                        <div class="grid md:grid-cols-2 gap-6">
                            <div class="feature-block p-4 border border-gray-200 dark:border-gray-700 rounded-lg">
                                <h3 class="text-xl font-semibold text-gray-800 dark:text-white">
                                    <i class="fas fa-globe-americas mr-2 text-yellow-500"></i>Nationwide Access
                                </h3>
                                <p class="text-gray-700 dark:text-gray-300 mt-2">
                                    With FeatureGym, your membership travels with you. Whether you're at home, on a business trip, or vacation, you'll have access to our network of partner gyms across the country.
                                </p>
                            </div>
                            
                            <div class="feature-block p-4 border border-gray-200 dark:border-gray-700 rounded-lg">
                                <h3 class="text-xl font-semibold text-gray-800 dark:text-white">
                                    <i class="fas fa-calendar-alt mr-2 text-yellow-500"></i>Flexible Scheduling
                                </h3>
                                <p class="text-gray-700 dark:text-gray-300 mt-2">
                                    Life is unpredictable. That's why we've designed our platform to allow easy rescheduling and cancellations. When you cancel a session, we automatically extend your membership validity - ensuring you get full value for your investment.
                                </p>
                            </div>
                            
                            <div class="feature-block p-4 border border-gray-200 dark:border-gray-700 rounded-lg">
                                <h3 class="text-xl font-semibold text-gray-800 dark:text-white">
                                    <i class="fas fa-tag mr-2 text-yellow-500"></i>Transparent Pricing
                                </h3>
                                <p class="text-gray-700 dark:text-gray-300 mt-2">
                                    No hidden fees, no complicated contracts. Our straightforward pricing ensures you know exactly what you're paying for - quality access to fitness facilities whenever and wherever you need them.
                                </p>
                            </div>
                            
                            <div class="feature-block p-4 border border-gray-200 dark:border-gray-700 rounded-lg">
                                <h3 class="text-xl font-semibold text-gray-800 dark:text-white">
                                    <i class="fas fa-dumbbell mr-2 text-yellow-500"></i>Diverse Gym Network
                                </h3>
                                <p class="text-gray-700 dark:text-gray-300 mt-2">
                                    From boutique fitness studios to comprehensive health clubs, our network includes a diverse range of facilities to suit different preferences and workout styles.
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="section mb-8 bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                        <h2 class="text-2xl font-bold text-yellow-600 dark:text-yellow-400 mb-4">How It Works</h2>
                        <div class="flex flex-col md:flex-row justify-between items-center space-y-6 md:space-y-0 md:space-x-4">
                            <div class="step flex flex-col items-center text-center p-4">
                                <div class="w-16 h-16 bg-yellow-100 dark:bg-yellow-900 rounded-full flex items-center justify-center mb-3">
                                    <i class="fas fa-user-plus text-2xl text-yellow-500"></i>
                                </div>
                                <h3 class="text-lg font-semibold text-gray-800 dark:text-white">Sign Up</h3>
                                <p class="text-gray-700 dark:text-gray-300 mt-2">Create your account and choose a membership plan</p>
                            </div>
                            
                            <div class="step flex flex-col items-center text-center p-4">
                                <div class="w-16 h-16 bg-yellow-100 dark:bg-yellow-900 rounded-full flex items-center justify-center mb-3">
                                    <i class="fas fa-search text-2xl text-yellow-500"></i>
                                </div>
                                <h3 class="text-lg font-semibold text-gray-800 dark:text-white">Discover</h3>
                                <p class="text-gray-700 dark:text-gray-300 mt-2">Browse our network of partner gyms</p>
                            </div>
                            
                            <div class="step flex flex-col items-center text-center p-4">
                                <div class="w-16 h-16 bg-yellow-100 dark:bg-yellow-900 rounded-full flex items-center justify-center mb-3">
                                    <i class="fas fa-calendar-check text-2xl text-yellow-500"></i>
                                </div>
                                <h3 class="text-lg font-semibold text-gray-800 dark:text-white">Book</h3>
                                <p class="text-gray-700 dark:text-gray-300 mt-2">Reserve your session with a few clicks</p>
                            </div>
                            
                            <div class="step flex flex-col items-center text-center p-4">
                                <div class="w-16 h-16 bg-yellow-100 dark:bg-yellow-900 rounded-full flex items-center justify-center mb-3">
                                    <i class="fas fa-running text-2xl text-yellow-500"></i>
                                </div>
                                <h3 class="text-lg font-semibold text-gray-800 dark:text-white">Work Out</h3>
                                <p class="text-gray-700 dark:text-gray-300 mt-2">Check in through our app and enjoy</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="section mb-8 bg-white dark:bg-gray-800 p-6 rounded-lg shadow-md">
                        <h2 class="text-2xl font-bold text-yellow-600 dark:text-yellow-400 mb-4">Our Values</h2>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="value-block p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                <h3 class="text-xl font-semibold text-gray-800 dark:text-white flex items-center">
                                    <i class="fas fa-universal-access mr-2 text-yellow-500"></i>Accessibility
                                </h3>
                                <p class="text-gray-700 dark:text-gray-300 mt-2">We believe fitness should be accessible to everyone, everywhere.</p>
                            </div>
                            
                            <div class="value-block p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                <h3 class="text-xl font-semibold text-gray-800 dark:text-white flex items-center">
                                    <i class="fas fa-arrows-alt mr-2 text-yellow-500"></i>Flexibility
                                </h3>
                                <p class="text-gray-700 dark:text-gray-300 mt-2">We adapt to your lifestyle, not the other way around.</p>
                            </div>
                            
                            <div class="value-block p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                <h3 class="text-xl font-semibold text-gray-800 dark:text-white flex items-center">
                                    <i class="fas fa-glasses mr-2 text-yellow-500"></i>Transparency
                                </h3>
                                <p class="text-gray-700 dark:text-gray-300 mt-2">We maintain clear, honest communication in all our dealings.</p>
                            </div>
                            
                            <div class="value-block p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                <h3 class="text-xl font-semibold text-gray-800 dark:text-white flex items-center">
                                    <i class="fas fa-lightbulb mr-2 text-yellow-500"></i>Innovation
                                </h3>
                                <p class="text-gray-700 dark:text-gray-300 mt-2">We continuously improve our platform to enhance your fitness journey.</p>
                            </div>
                            
                            <div class="value-block p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                <h3 class="text-xl font-semibold text-gray-800 dark:text-white flex items-center">
                                    <i class="fas fa-users mr-2 text-yellow-500"></i>Community
                                </h3>
                                <p class="text-gray-700 dark:text-gray-300 mt-2">We foster a supportive network of fitness enthusiasts and partners.</p>
                            </div>
                            
                            <div class="value-block p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                <h3 class="text-xl font-semibold text-gray-800 dark:text-white flex items-center">
                                    <i class="fas fa-shield-alt mr-2 text-yellow-500"></i>Quality
                                </h3>
                                <p class="text-gray-700 dark:text-gray-300 mt-2">We ensure high standards across our gym network and services.</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="cta-section text-center mt-10 bg-gradient-to-r from-yellow-500 to-yellow-600 dark:from-yellow-600 dark:to-yellow-700 p-8 rounded-lg shadow-lg">
                        <h2 class="text-2xl font-bold text-white mb-4">Join the FeatureGym Community Today</h2>
                        <p class="text-white mb-6">
                            Experience the freedom of fitness without boundaries. Whether you're a frequent traveler, a fitness enthusiast, or someone looking for more flexibility in your workout routine, FeatureGym is designed for you.
                        </p>
                        <a href="register.php" class="inline-block bg-white text-yellow-600 hover:bg-gray-100 px-8 py-3 rounded-lg text-lg font-semibold shadow-md transition duration-300 ease-in-out transform hover:-translate-y-1">
                            Join Now
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<?php include 'includes/footer.php'; ?>

