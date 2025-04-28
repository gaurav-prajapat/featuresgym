<?php
require_once 'config/database.php';

// Create database connection
$db = new GymDatabase();
$conn = $db->getConnection();

// Get FAQ content from system settings
$faqs = [];
try {
    // Check if we have FAQs in the system_settings table
    $faqQuery = "SELECT setting_value FROM system_settings WHERE setting_key = 'faq_content'";
    $faqStmt = $conn->prepare($faqQuery);
    $faqStmt->execute();
    $faqContent = $faqStmt->fetchColumn();
    
    if ($faqContent) {
        $faqs = json_decode($faqContent, true);
    }
    
    // If no FAQs found or decoding failed, use default FAQs
    if (empty($faqs) || !is_array($faqs)) {
        $faqs = [
            [
                'question' => 'How do I sign up for a gym membership?',
                'answer' => 'To sign up for a gym membership, browse our available gyms, select a plan that suits your needs, and complete the registration process. You can pay online and start using your membership immediately.'
            ],
            [
                'question' => 'Can I cancel my membership?',
                'answer' => 'Yes, you can cancel your membership at any time. Please note that refunds are subject to our refund policy and may be prorated based on the time remaining in your membership period.'
            ],
            [
                'question' => 'How do I schedule a gym session?',
                'answer' => 'Once you have an active membership, you can schedule gym sessions through your dashboard. Simply select the date and time that works for you, and confirm your booking.'
            ],
            [
                'question' => 'What happens if I miss a scheduled session?',
                'answer' => 'If you miss a scheduled session, it will be marked as "missed" in your account. We recommend canceling sessions in advance if you know you cannot attend to maintain a good attendance record.'
            ],
            [
                'question' => 'How do I participate in tournaments?',
                'answer' => 'You can view upcoming tournaments on our tournaments page. To participate, register for the event and pay any applicable entry fees. Make sure to check the tournament details for specific requirements and schedules.'
            ],
            [
                'question' => 'Can I change my gym location?',
                'answer' => 'Yes, you can change your gym location. Go to your membership details and select the option to update your gym. Depending on the new location, there may be price adjustments or transfer fees.'
            ],
            [
                'question' => 'How do I contact customer support?',
                'answer' => 'You can reach our customer support team through the contact form on our website, by email at support@gymsite.com, or by phone at +1-800-GYM-HELP during business hours.'
            ]
        ];
    }
} catch (PDOException $e) {
    // If there's an error, use default FAQs
    $faqs = [
        [
            'question' => 'How do I sign up for a gym membership?',
            'answer' => 'To sign up for a gym membership, browse our available gyms, select a plan that suits your needs, and complete the registration process. You can pay online and start using your membership immediately.'
        ],
        [
            'question' => 'Can I cancel my membership?',
            'answer' => 'Yes, you can cancel your membership at any time. Please note that refunds are subject to our refund policy and may be prorated based on the time remaining in your membership period.'
        ],
        [
            'question' => 'How do I schedule a gym session?',
            'answer' => 'Once you have an active membership, you can schedule gym sessions through your dashboard. Simply select the date and time that works for you, and confirm your booking.'
        ],
        [
            'question' => 'What happens if I miss a scheduled session?',
            'answer' => 'If you miss a scheduled session, it will be marked as "missed" in your account. We recommend canceling sessions in advance if you know you cannot attend to maintain a good attendance record.'
        ],
        [
            'question' => 'How do I participate in tournaments?',
            'answer' => 'You can view upcoming tournaments on our tournaments page. To participate, register for the event and pay any applicable entry fees. Make sure to check the tournament details for specific requirements and schedules.'
        ],
        [
            'question' => 'Can I change my gym location?',
            'answer' => 'Yes, you can change your gym location. Go to your membership details and select the option to update your gym. Depending on the new location, there may be price adjustments or transfer fees.'
        ],
        [
            'question' => 'How do I contact customer support?',
            'answer' => 'You can reach our customer support team through the contact form on our website, by email at support@gymsite.com, or by phone at +1-800-GYM-HELP during business hours.'
        ]
    ];
}

// Get page title and description from settings
try {
    $titleQuery = "SELECT setting_value FROM system_settings WHERE setting_key = 'faq_page_title'";
    $titleStmt = $conn->prepare($titleQuery);
    $titleStmt->execute();
    $pageTitle = $titleStmt->fetchColumn();
    
    $descQuery = "SELECT setting_value FROM system_settings WHERE setting_key = 'faq_page_description'";
    $descStmt = $conn->prepare($descQuery);
    $descStmt->execute();
    $pageDescription = $descStmt->fetchColumn();
    
    // Use defaults if not found
    if (!$pageTitle) {
        $pageTitle = 'Frequently Asked Questions';
    }
    
    if (!$pageDescription) {
        $pageDescription = 'Find answers to common questions about our services';
    }
} catch (PDOException $e) {
    $pageTitle = 'Frequently Asked Questions';
    $pageDescription = 'Find answers to common questions about our services';
}

include 'includes/navbar.php';
?>

<div class="min-h-screen bg-gradient-to-b from-gray-900 to-black py-12">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8">
        <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden mb-8">
            <div class="p-6 bg-gradient-to-r from-yellow-400 to-yellow-500">
                <h1 class="text-4xl font-bold text-gray-900 text-center"><?= htmlspecialchars($pageTitle) ?></h1>
                <p class="text-lg text-gray-800 text-center mt-2"><?= htmlspecialchars($pageDescription) ?></p>
            </div>
        </div>

        <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden p-6 mb-8">
            <div class="space-y-6">
                <?php foreach ($faqs as $index => $faq): ?>
                <!-- FAQ Item -->
                <div class="<?= $index < count($faqs) - 1 ? 'border-b border-gray-700 pb-6' : 'pb-6' ?>">
                    <h3 class="text-xl font-semibold text-yellow-400 mb-2"><?= htmlspecialchars($faq['question']) ?></h3>
                    <p class="text-gray-300"><?= htmlspecialchars($faq['answer']) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <?php
        // Check if contact info should be displayed (from settings)
        $showContactInfo = true;
        try {
            $contactQuery = "SELECT setting_value FROM system_settings WHERE setting_key = 'show_contact_on_faq'";
            $contactStmt = $conn->prepare($contactQuery);
            $contactStmt->execute();
            $showContactInfo = $contactStmt->fetchColumn() !== '0';
        } catch (PDOException $e) {
            // Default to true if error
            $showContactInfo = true;
        }
        
        if ($showContactInfo):
        ?>
        <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden p-6">
            <h2 class="text-2xl font-bold text-yellow-400 mb-4 text-center">Still Have Questions?</h2>
            <p class="text-gray-300 text-center mb-6">Our support team is here to help you with any other questions you might have.</p>
            
            <div class="flex flex-col md:flex-row justify-center items-center space-y-4 md:space-y-0 md:space-x-6">
                <a href="<?= getPageUrl('contact.php') ?>" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-3 px-6 rounded-lg transition duration-300 flex items-center justify-center">
                    <i class="fas fa-envelope mr-2"></i> Contact Us
                </a>
                <?php
                // Get support email from settings
                try {
                    $emailQuery = "SELECT setting_value FROM system_settings WHERE setting_key = 'contact_email'";
                    $emailStmt = $conn->prepare($emailQuery);
                    $emailStmt->execute();
                    $supportEmail = $emailStmt->fetchColumn();
                    
                    if ($supportEmail):
                ?>
                <a href="mailto:<?= htmlspecialchars($supportEmail) ?>" class="bg-gray-700 hover:bg-gray-600 text-white font-bold py-3 px-6 rounded-lg transition duration-300 flex items-center justify-center">
                    <i class="fas fa-at mr-2"></i> <?= htmlspecialchars($supportEmail) ?>
                </a>
                <?php 
                    endif;
                } catch (PDOException $e) {
                    // Do nothing if error
                }
                
                // Get support phone from settings
                try {
                    $phoneQuery = "SELECT setting_value FROM system_settings WHERE setting_key = 'contact_phone'";
                    $phoneStmt = $conn->prepare($phoneQuery);
                    $phoneStmt->execute();
                    $supportPhone = $phoneStmt->fetchColumn();
                    
                    if ($supportPhone):
                ?>
                <a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $supportPhone)) ?>" class="bg-gray-700 hover:bg-gray-600 text-white font-bold py-3 px-6 rounded-lg transition duration-300 flex items-center justify-center">
                    <i class="fas fa-phone mr-2"></i> <?= htmlspecialchars($supportPhone) ?>
                </a>
                <?php 
                    endif;
                } catch (PDOException $e) {
                    // Do nothing if error
                }
                ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
