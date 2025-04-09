<?php
// Get footer settings from database
$footerSettings = [];
try {
    if (isset($conn)) {
        $sql = "SELECT * FROM site_settings WHERE setting_group = 'footer'";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($results as $row) {
            $footerSettings[$row['setting_key']] = $row['setting_value'];
        }
    }
} catch (Exception $e) {
    // Silently fail and use defaults
}

// Default values if not set in database
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
    if (!isset($footerSettings[$key])) {
        $footerSettings[$key] = $value;
    }
}

// Get brand name parts for styling
$brandParts = explode(' ', $footerSettings['brand_name'], 2);
$brandFirst = $brandParts[0] ?? 'FIT';
$brandRest = isset($brandParts[1]) ? $brandParts[1] : 'Fit';
?>

<!-- Footer -->
<footer class="bg-gradient-to-b <?php echo $footerSettings['footer_bg_color']; ?> relative pt-20 pb-10">
    <div class="absolute inset-0 bg-pattern opacity-10"></div>
    
    <div class="container mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
        <!-- Footer Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-12 mb-16">
            <!-- Brand Section -->
            <div>
                <h3 class="text-2xl font-bold <?php echo $footerSettings['footer_text_color']; ?> mb-6">
                    <span class="<?php echo $footerSettings['footer_accent_color']; ?>"><?php echo $brandFirst; ?></span><?php echo $brandRest; ?>
                </h3>
                <p class="<?php echo $footerSettings['footer_text_color']; ?> mb-6"><?php echo htmlspecialchars($footerSettings['tagline']); ?></p>
                <div class="flex space-x-4">
                    <a href="<?php echo htmlspecialchars($footerSettings['facebook_url']); ?>" class="<?php echo $footerSettings['footer_text_color'] . ' ' . $footerSettings['footer_hover_color']; ?> transition-colors">
                        <i class="fab fa-facebook-f text-xl"></i>
                    </a>
                    <a href="<?php echo htmlspecialchars($footerSettings['instagram_url']); ?>" class="<?php echo $footerSettings['footer_text_color'] . ' ' . $footerSettings['footer_hover_color']; ?> transition-colors">
                        <i class="fab fa-instagram text-xl"></i>
                    </a>
                    <a href="<?php echo htmlspecialchars($footerSettings['twitter_url']); ?>" class="<?php echo $footerSettings['footer_text_color'] . ' ' . $footerSettings['footer_hover_color']; ?> transition-colors">
                        <i class="fab fa-twitter text-xl"></i>
                    </a>
                </div>
            </div>

            <!-- Quick Links -->
            <div>
                <h4 class="text-lg font-bold <?php echo $footerSettings['footer_text_color']; ?> mb-6">Quick Links</h4>
                <ul class="space-y-4">
                    <li><a href="<?php echo htmlspecialchars($footerSettings['about_url']); ?>" class="<?php echo $footerSettings['footer_text_color'] . ' ' . $footerSettings['footer_hover_color']; ?> transition-colors">About Us</a></li>
                    <li><a href="<?php echo htmlspecialchars($footerSettings['gyms_url']); ?>" class="<?php echo $footerSettings['footer_text_color'] . ' ' . $footerSettings['footer_hover_color']; ?> transition-colors">Find Gyms</a></li>
                    <li><a href="<?php echo htmlspecialchars($footerSettings['trainers_url']); ?>" class="<?php echo $footerSettings['footer_text_color'] . ' ' . $footerSettings['footer_hover_color']; ?> transition-colors">Trainers</a></li>
                    <li><a href="<?php echo htmlspecialchars($footerSettings['membership_url']); ?>" class="<?php echo $footerSettings['footer_text_color'] . ' ' . $footerSettings['footer_hover_color']; ?> transition-colors">Membership</a></li>
                </ul>
            </div>

            <!-- Support -->
            <div>
                <h4 class="text-lg font-bold <?php echo $footerSettings['footer_text_color']; ?> mb-6">Support</h4>
                <ul class="space-y-4">
                    <li><a href="<?php echo htmlspecialchars($footerSettings['contact_url']); ?>" class="<?php echo $footerSettings['footer_text_color'] . ' ' . $footerSettings['footer_hover_color']; ?> transition-colors">Contact Us</a></li>
                    <li><a href="<?php echo htmlspecialchars($footerSettings['faq_url']); ?>" class="<?php echo $footerSettings['footer_text_color'] . ' ' . $footerSettings['footer_hover_color']; ?> transition-colors">FAQs</a></li>
                    <li><a href="<?php echo htmlspecialchars($footerSettings['privacy_url']); ?>" class="<?php echo $footerSettings['footer_text_color'] . ' ' . $footerSettings['footer_hover_color']; ?> transition-colors">Privacy Policy</a></li>
                    <li><a href="<?php echo htmlspecialchars($footerSettings['terms_url']); ?>" class="<?php echo $footerSettings['footer_text_color'] . ' ' . $footerSettings['footer_hover_color']; ?> transition-colors">Terms of Service</a></li>
                </ul>
            </div>

            <!-- Contact Info -->
            <div>
                <h4 class="text-lg font-bold <?php echo $footerSettings['footer_text_color']; ?> mb-6">Contact Info</h4>
                <ul class="space-y-4">
                    <li class="flex items-start space-x-3">
                        <i class="fas fa-map-marker-alt <?php echo $footerSettings['footer_accent_color']; ?> mt-1"></i>
                        <span class="<?php echo $footerSettings['footer_text_color']; ?>"><?php echo htmlspecialchars($footerSettings['address']); ?></span>
                    </li>
                    <li class="flex items-center space-x-3">
                        <i class="fas fa-phone <?php echo $footerSettings['footer_accent_color']; ?>"></i>
                        <span class="<?php echo $footerSettings['footer_text_color']; ?>"><?php echo htmlspecialchars($footerSettings['phone']); ?></span>
                    </li>
                    <li class="flex items-center space-x-3">
                        <i class="fas fa-envelope <?php echo $footerSettings['footer_accent_color']; ?>"></i>
                        <span class="<?php echo $footerSettings['footer_text_color']; ?>"><?php echo htmlspecialchars($footerSettings['email']); ?></span>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Bottom Bar -->
        <div class="border-t border-gray-800 pt-8">
            <div class="flex flex-col md:flex-row justify-between items-center">
                <p class="<?php echo $footerSettings['footer_text_color']; ?> text-sm mb-4 md:mb-0">
                    <?php echo htmlspecialchars($footerSettings['copyright_text']); ?>
                </p>
                <div class="flex space-x-6">
                    <a href="<?php echo htmlspecialchars($footerSettings['privacy_url']); ?>" class="<?php echo $footerSettings['footer_text_color'] . ' ' . $footerSettings['footer_hover_color']; ?> text-sm transition-colors">Privacy Policy</a>
                    <a href="<?php echo htmlspecialchars($footerSettings['terms_url']); ?>" class="<?php echo $footerSettings['footer_text_color'] . ' ' . $footerSettings['footer_hover_color']; ?> text-sm transition-colors">Terms of Service</a>
                    <a href="<?php echo htmlspecialchars($footerSettings['cookie_url']); ?>" class="<?php echo $footerSettings['footer_text_color'] . ' ' . $footerSettings['footer_hover_color']; ?> text-sm transition-colors">Cookie Policy</a>
                </div>
            </div>
        </div>
    </div>
</footer>

</body>
</html>
