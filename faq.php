<?php
require_once 'config/database.php';
include 'includes/navbar.php';
?>

<div class="min-h-screen bg-gradient-to-b from-gray-900 to-black py-12">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8">
        <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden mb-8">
            <div class="p-6 bg-gradient-to-r from-yellow-400 to-yellow-500">
                <h1 class="text-4xl font-bold text-gray-900 text-center">Frequently Asked Questions</h1>
                <p class="text-lg text-gray-800 text-center mt-2">Find answers to common questions about our services</p>
            </div>
        </div>

        <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden p-6 mb-8">
            <div class="space-y-6">
                <!-- FAQ Item -->
                <div class="border-b border-gray-700 pb-6">
                    <h3 class="text-xl font-semibold text-yellow-400 mb-2">How do I sign up for a gym membership?</h3>
                    <p class="text-gray-300">To sign up for a gym membership, browse our available gyms, select a plan that suits your needs, and complete the registration process. You can pay online and start using your membership immediately.</p>
                </div>

                <!-- FAQ Item -->
                <div class="border-b border-gray-700 pb-6">
                    <h3 class="text-xl font-semibold text-yellow-400 mb-2">Can I cancel my membership?</h3>
                    <p class="text-gray-300">Yes, you can cancel your membership at any time. Please note that refunds are subject to our refund policy and may be prorated based on the time remaining in your membership period.</p>
                </div>

                <!-- FAQ Item -->
                <div class="border-b border-gray-700 pb-6">
                    <h3 class="text-xl font-semibold text-yellow-400 mb-2">How do I schedule a gym session?</h3>
                    <p class="text-gray-300">Once you have an active membership, you can schedule gym sessions through your dashboard. Simply select the date and time that works for you, and confirm your booking.</p>
                </div>

                <!-- FAQ Item -->
                <div class="border-b border-gray-700 pb-6">
                    <h3 class="text-xl font-semibold text-yellow-400 mb-2">What happens if I miss a scheduled session?</h3>
                    <p class="text-gray-300">If you miss a scheduled session, it will be marked as "missed" in your account. We recommend canceling sessions in advance if you know you cannot attend to maintain a good attendance record.</p>
                </div>

                <!-- FAQ Item -->
                <div class="border-b border-gray-700 pb-6">
                    <h3 class="text-xl font-semibold text-yellow-400 mb-2">How do I participate in tournaments?</h3>
                    <p class="text-gray-300">You can view upcoming tournaments on our tournaments page. To participate, register for the event and pay any applicable entry fees. Make sure to check the tournament details for specific requirements and schedules.</p>
                </div>

                <!-- FAQ Item -->
                <div class="border-b border-gray-700 pb-6">
                    <h3 class="text-xl font-semibold text-yellow-400 mb-2">Can I change my gym location?</h3>
                    <p class="text-gray-300">Yes, you can change your gym location. Go to your membership details and select the option to update your gym. Depending on the new location, there may be price adjustments or transfer fees.</p>
                </div>

                <!-- FAQ Item -->
                <div class="pb-6">
                    <h3 class="text-xl font-semibold text-yellow-400 mb-2">How do I contact customer support?</h3>
                    <p class="text-gray-300">You can reach our customer support team through the contact form on our website, by email at support@gymsite.com, or by phone at +1-800-GYM-HELP during business hours.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
