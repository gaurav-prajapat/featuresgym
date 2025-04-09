<?php
ob_start();
require_once 'config/database.php';
include 'includes/navbar.php';
// Check if the user is logged in
if (isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
?>

<div class="min-h-screen bg-gradient-to-b from-gray-900 to-black py-12">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8">
        <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden mb-8">
            <div class="p-6 bg-gradient-to-r from-yellow-400 to-yellow-500">
                <h1 class="text-4xl font-bold text-gray-900 text-center">Terms of Service</h1>
                <p class="text-lg text-gray-800 text-center mt-2">Last Updated: <?php echo date('F d, Y'); ?></p>
            </div>
        </div>

        <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden p-6 mb-8">
            <div class="prose prose-lg max-w-none text-gray-300">
                <h2 class="text-2xl font-semibold text-yellow-400 mb-4">1. Acceptance of Terms</h2>
                <p>By accessing or using our gym management platform, you agree to be bound by these Terms of Service and all applicable laws and regulations. If you do not agree with any of these terms, you are prohibited from using or accessing this platform.</p>
                
                <h2 class="text-2xl font-semibold text-yellow-400 mt-6 mb-4">2. User Accounts</h2>
                <p>When you create an account with us, you must provide accurate, complete, and current information. You are responsible for safeguarding the password and for all activities that occur under your account.</p>
                <p>You agree to notify us immediately of any unauthorized use of your account or any other breach of security. We cannot and will not be liable for any loss or damage arising from your failure to comply with this section.</p>
                
                <h2 class="text-2xl font-semibold text-yellow-400 mt-6 mb-4">3. Membership and Payments</h2>
                <p>By purchasing a gym membership through our platform, you agree to pay all fees associated with the membership plan you select. All payments are processed securely through our payment processors.</p>
                <p>Membership fees are non-refundable except as specifically provided in our refund policy. We reserve the right to modify membership pricing with appropriate notice to users.</p>
                <p>Recurring memberships will automatically renew unless canceled before the renewal date. You can cancel your membership at any time through your account settings.</p>
                
                <h2 class="text-2xl font-semibold text-yellow-400 mt-6 mb-4">4. Scheduling and Attendance</h2>
                <p>Our platform allows you to schedule gym sessions based on availability. You are responsible for attending your scheduled sessions or canceling them in advance according to the gym's cancellation policy.</p>
                <p>Repeated no-shows or late cancellations may result in penalties or restrictions on future bookings as determined by individual gym policies.</p>
                
                <h2 class="text-2xl font-semibold text-yellow-400 mt-6 mb-4">5. Tournament Participation</h2>
                <p>Tournament registrations are subject to specific terms and conditions for each event. Entry fees may be non-refundable after registration deadlines.</p>
                <p>By registering for tournaments, you agree to follow all rules and regulations established for the event and to conduct yourself in a sportsmanlike manner.</p>
                
                <h2 class="text-2xl font-semibold text-yellow-400 mt-6 mb-4">6. User Conduct</h2>
                <p>You agree not to use our platform for any purpose that is unlawful or prohibited by these Terms. You may not:</p>
                <ul class="list-disc pl-6 mb-4">
                    <li>Use the platform in any way that could damage, disable, or impair the platform</li>
                    <li>Attempt to gain unauthorized access to any part of the platform</li>
                    <li>Use automated means to access or collect data from the platform</li>
                    <li>Harass, abuse, or harm another person through the platform</li>
                    <li>Submit false or misleading information</li>
                    <li>Infringe upon the intellectual property rights of others</li>
                </ul>
                
                <h2 class="text-2xl font-semibold text-yellow-400 mt-6 mb-4">7. Intellectual Property</h2>
                <p>The platform and its original content, features, and functionality are owned by us and are protected by international copyright, trademark, and other intellectual property laws.</p>
                <p>You may not reproduce, distribute, modify, create derivative works of, publicly display, publicly perform, republish, download, store, or transmit any of the material on our platform without our prior written consent.</p>
                
                <h2 class="text-2xl font-semibold text-yellow-400 mt-6 mb-4">8. Limitation of Liability</h2>
                <p>To the fullest extent permitted by applicable law, we shall not be liable for any indirect, incidental, special, consequential, or punitive damages, including but not limited to, loss of profits, data, use, goodwill, or other intangible losses, resulting from:</p>
                <ul class="list-disc pl-6 mb-4">
                    <li>Your access to or use of or inability to access or use the platform</li>
                    <li>Any conduct or content of any third party on the platform</li>
                    <li>Any content obtained from the platform</li>
                    <li>Unauthorized access, use, or alteration of your transmissions or content</li>
                </ul>
                
                <h2 class="text-2xl font-semibold text-yellow-400 mt-6 mb-4">9. Indemnification</h2>
                <p>You agree to defend, indemnify, and hold harmless our company, its affiliates, and their respective directors, officers, employees, and agents from and against all claims, damages, obligations, losses, liabilities, costs or debt, and expenses arising from your use of the platform.</p>
                
                <h2 class="text-2xl font-semibold text-yellow-400 mt-6 mb-4">10. Termination</h2>
                <p>We may terminate or suspend your account and access to the platform immediately, without prior notice or liability, for any reason, including but not limited to a breach of these Terms.</p>
                <p>Upon termination, your right to use the platform will immediately cease. If you wish to terminate your account, you may simply discontinue using the platform or contact us to request account deletion.</p>
                
                <h2 class="text-2xl font-semibold text-yellow-400 mt-6 mb-4">11. Governing Law</h2>
                <p>These Terms shall be governed by and construed in accordance with the laws of India, without regard to its conflict of law provisions.</p>
                <p>Our failure to enforce any right or provision of these Terms will not be considered a waiver of those rights. If any provision of these Terms is held to be invalid or unenforceable, the remaining provisions will remain in effect.</p>
                
                <h2 class="text-2xl font-semibold text-yellow-400 mt-6 mb-4">12. Changes to Terms</h2>
                <p>We reserve the right to modify or replace these Terms at any time. We will provide notice of any material changes by posting the new Terms on this page and updating the "Last Updated" date.</p>
                <p>By continuing to access or use our platform after any revisions become effective, you agree to be bound by the revised terms. If you do not agree to the new terms, you are no longer authorized to use the platform.</p>
                
                <h2 class="text-2xl font-semibold text-yellow-400 mt-6 mb-4">13. Dispute Resolution</h2>
                <p>Any disputes arising out of or relating to these Terms or your use of the platform shall first be attempted to be resolved through good faith negotiations. If such disputes cannot be resolved through negotiation, they shall be submitted to binding arbitration in accordance with the rules of the Indian Arbitration Association.</p>
                
                <h2 class="text-2xl font-semibold text-yellow-400 mt-6 mb-4">14. Entire Agreement</h2>
                <p>These Terms constitute the entire agreement between you and us regarding our platform and supersede all prior and contemporaneous written or oral agreements between you and us.</p>
                
                <h2 class="text-2xl font-semibold text-yellow-400 mt-6 mb-4">15. Contact Information</h2>
                <p>If you have any questions about these Terms, please contact us at:</p>
                <p>Email: legal@gymsite.com<br>
                Phone: +1-800-GYM-HELP<br>
                Address: 123 Fitness Street, Workout City, WO 12345</p>
                
                <div class="mt-8 p-4 bg-gray-700 bg-opacity-50 rounded-lg">
                    <p class="font-semibold">By using our platform, you acknowledge that you have read these Terms of Service, understand them, and agree to be bound by them.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

