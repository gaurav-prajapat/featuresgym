<?php
// Check if admin is logged in
session_start();
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    echo 'Unauthorized access';
    exit;
}

// Process POST data
$settings = [];
foreach ($_POST as $key => $value) {
    if ($key !== 'generate_code') {
        $settings[$key] = trim($value);
    }
}

// Default values if not set
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
    if (!isset($settings[$key])) {
        $settings[$key] = $value;
    }
}

// Generate footer code
$code = <<<EOT
<!-- Footer -->
<footer class="bg-gradient-to-b {$settings['footer_bg_color']} relative pt-20 pb-10">
    <div class="absolute inset-0 bg-pattern opacity-10"></div>
    
    <div class="container mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
        <!-- Footer Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-12 mb-16">
            <!-- Brand Section -->
            <div>
                <h3 class="text-2xl font-bold {$settings['footer_text_color']} mb-6">
                    <span class="{$settings['footer_accent_color']}">FIT</span>CONNECT
                </h3>
                <p class="{$settings['footer_text_color']} mb-6">{$settings['tagline']}</p>
                <div class="flex space-x-4">
                    <a href="{$settings['facebook_url']}" class="{$settings['footer_text_color']} {$settings['footer_hover_color']} transition-colors">
                        <i class="fab fa-facebook-f text-xl"></i>
                    </a>
                    <a href="{$settings['instagram_url']}" class="{$settings['footer_text_color']} {$settings['footer_hover_color']} transition-colors">
                        <i class="fab fa-instagram text-xl"></i>
                    </a>
                    <a href="{$settings['twitter_url']}" class="{$settings['footer_text_color']} {$settings['footer_hover_color']} transition-colors">
                        <i class="fab fa-twitter text-xl"></i>
                    </a>
                </div>
            </div>

            <!-- Quick Links -->
            <div>
                <h4 class="text-lg font-bold {$settings['footer_text_color']} mb-6">Quick Links</h4>
                <ul class="space-y-4">
                    <li><a href="{$settings['about_url']}" class="{$settings['footer_text_color']} {$settings['footer_hover_color']} transition-colors">About Us</a></li>
                    <li><a href="{$settings['gyms_url']}" class="{$settings['footer_text_color']} {$settings['footer_hover_color']} transition-colors">Find Gyms</a></li>
                    <li><a href="{$settings['trainers_url']}" class="{$settings['footer_text_color']} {$settings['footer_hover_color']} transition-colors">Trainers</a></li>
                    <li><a href="{$settings['membership_url']}" class="{$settings['footer_text_color']} {$settings['footer_hover_color']} transition-colors">Membership</a></li>
                </ul>
            </div>

            <!-- Support -->
            <div>
                <h4 class="text-lg font-bold {$settings['footer_text_color']} mb-6">Support</h4>
                <ul class="space-y-4">
                    <li><a href="{$settings['contact_url']}" class="{$settings['footer_text_color']} {$settings['footer_hover_color']} transition-colors">Contact Us</a></li>
                    <li><a href="{$settings['faq_url']}" class="{$settings['footer_text_color']} {$settings['footer_hover_color']} transition-colors">FAQs</a></li>
                    <li><a href="{$settings['privacy_url']}" class="{$settings['footer_text_color']} {$settings['footer_hover_color']} transition-colors">Privacy Policy</a></li>
                    <li><a href="{$settings['terms_url']}" class="{$settings['footer_text_color']} {$settings['footer_hover_color']} transition-colors">Terms of Service</a></li>
                </ul>
            </div>

            <!-- Contact Info -->
            <div>
                <h4 class="text-lg font-bold {$settings['footer_text_color']} mb-6">Contact Info</h4>
                <ul class="space-y-4">
                    <li class="flex items-start space-x-3">
                        <i class="fas fa-map-marker-alt {$settings['footer_accent_color']} mt-1"></i>
                        <span class="{$settings['footer_text_color']}">{$settings['address']}</span>
                    </li>
                    <li class="flex items-center space-x-3">
                        <i class="fas fa-phone {$settings['footer_accent_color']}"></i>
                        <span class="{$settings['footer_text_color']}">{$settings['phone']}</span>
                    </li>
                    <li class="flex items-center space-x-3">
                        <i class="fas fa-envelope {$settings['footer_accent_color']}"></i>
                        <span class="{$settings['footer_text_color']}">{$settings['email']}</span>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Bottom Bar -->
        <div class="border-t border-gray-800 pt-8">
            <div class="flex flex-col md:flex-row justify-between items-center">
                <p class="{$settings['footer_text_color']} text-sm mb-4 md:mb-0">
                    © <?php echo date('Y'); ?> FitConnect. All rights reserved.
                </p>
                <div class="flex space-x-6">
                    <a href="{$settings['privacy_url']}" class="{$settings['footer_text_color']} {$settings['footer_hover_color']} text-sm transition-colors">Privacy Policy</a>
                    <a href="{$settings['terms_url']}" class="{$settings['footer_text_color']} {$settings['footer_hover_color']} text-sm transition-colors">Terms of Service</a>
                    <a href="{$settings['cookie_url']}" class="{$settings['footer_text_color']} {$settings['footer_hover_color']} text-sm transition-colors">Cookie Policy</a>
                </div>
            </div>
        </div>
    </div>
</footer>

</body>
</html>
EOT;

echo $code;
