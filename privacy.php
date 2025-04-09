<?php
require_once 'config/database.php';
include 'includes/navbar.php';
?>

<div class="min-h-screen bg-gradient-to-b from-gray-900 to-black py-12">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8">
        <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden mb-8">
            <div class="p-6 bg-gradient-to-r from-yellow-400 to-yellow-500">
                <h1 class="text-4xl font-bold text-gray-900 text-center">Privacy Policy</h1>
                <p class="text-lg text-gray-800 text-center mt-2">Last Updated: <?php echo date('F d, Y'); ?></p>
            </div>
        </div>

        <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden p-6 mb-8">
            <div class="prose prose-lg max-w-none text-gray-300">
                <h2 class="text-2xl font-semibold text-yellow-400 mb-4">1. Introduction</h2>
                <p>Welcome to our Privacy Policy. This document explains how we collect, use, and protect your personal information when you use our gym management platform.</p>
                <p>We respect your privacy and are committed to protecting your personal data. Please read this Privacy Policy carefully to understand our practices regarding your personal data.</p>
                
                <h2 class="text-2xl font-semibold text-yellow-400 mt-6 mb-4">2. Information We Collect</h2>
                <p>We collect several types of information from and about users of our platform, including:</p>
                <ul class="list-disc pl-6 mb-4">
                    <li>Personal identifiers such as name, email address, phone number, and profile image</li>
                    <li>Account information including username and password</li>
                    <li>Membership details and payment information</li>
                    <li>Gym attendance and scheduling information</li>
                    <li>Tournament participation records</li>
                    <li>Device and usage information collected automatically when you use our platform</li>
                </ul>
                
                <h2 class="text-2xl font-semibold text-yellow-400 mt-6 mb-4">3. How We Use Your Information</h2>
                <p>We use the information we collect about you for various purposes, including:</p>
                <ul class="list-disc pl-6 mb-4">
                    <li>Providing and maintaining our services</li>
                    <li>Processing membership registrations and payments</li>
                    <li>Managing gym schedules and attendance</li>
                    <li>Facilitating tournament registrations</li>
                    <li>Communicating with you about your account and our services</li>
                    <li>Improving our platform and user experience</li>
                    <li>Analyzing usage patterns and trends</li>
                </ul>
                
                <h2 class="text-2xl font-semibold text-yellow-400 mt-6 mb-4">4. Data Security</h2>
                <p>We implement appropriate security measures to protect your personal information from unauthorized access, alteration, disclosure, or destruction. These measures include:</p>
                <ul class="list-disc pl-6 mb-4">
                    <li>Secure socket layer (SSL) encryption for data transmission</li>
                    <li>Regular security assessments and updates</li>
                    <li>Access controls and authentication procedures</li>
                    <li>Secure storage of payment information</li>
                </ul>
                
                <h2 class="text-2xl font-semibold text-yellow-400 mt-6 mb-4">5. Data Sharing and Disclosure</h2>
                <p>We may share your information with:</p>
                <ul class="list-disc pl-6 mb-4">
                    <li>Gym partners to facilitate your membership and attendance</li>
                    <li>Payment processors to complete transactions</li>
                    <li>Service providers who assist in operating our platform</li>
                    <li>Legal authorities when required by law</li>
                </ul>
                <p>We do not sell your personal information to third parties.</p>
                
                <h2 class="text-2xl font-semibold text-yellow-400 mt-6 mb-4">6. Your Rights</h2>
                <p>Depending on your location, you may have certain rights regarding your personal information, including:</p>
                <ul class="list-disc pl-6 mb-4">
                    <li>The right to access your personal data</li>
                    <li>The right to correct inaccurate data</li>
                    <li>The right to request deletion of your data</li>
                    <li>The right to restrict or object to processing</li>
                    <li>The right to data portability</li>
                </ul>
                <p>To exercise these rights, please contact us using the information provided in the "Contact Us" section.</p>
                
                <h2 class="text-2xl font-semibold text-yellow-400 mt-6 mb-4">7. Cookies and Tracking Technologies</h2>
                <p>We use cookies and similar tracking technologies to enhance your experience on our platform. These technologies help us understand how you use our services, remember your preferences, and improve our offerings.</p>
                <p>You can control cookie settings through your browser preferences. However, disabling cookies may limit your ability to use certain features of our platform.</p>
                
                <h2 class="text-2xl font-semibold text-yellow-400 mt-6 mb-4">8. Changes to This Privacy Policy</h2>
                <p>We may update this Privacy Policy from time to time to reflect changes in our practices or for other operational, legal, or regulatory reasons. We will notify you of any material changes by posting the new Privacy Policy on this page and updating the "Last Updated" date.</p>
                <p>We encourage you to review this Privacy Policy periodically to stay informed about how we are protecting your information.</p>
                
                <h2 class="text-2xl font-semibold text-yellow-400 mt-6 mb-4">9. Contact Us</h2>
                <p>If you have any questions or concerns about this Privacy Policy or our data practices, please contact us at:</p>
                <p>Email: privacy@gymsite.com<br>
                Phone: +1-800-GYM-HELP<br>
                Address: 123 Fitness Street, Workout City, WO 12345</p>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
