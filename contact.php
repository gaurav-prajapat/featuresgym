<?php
require_once 'config/database.php';
$db = new GymDatabase();
$conn = $db->getConnection();

// Get contact information from system settings
try {
    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('contact_email', 'contact_phone', 'address', 'site_name')");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    $settings = [];
}

// Default values if settings are not found
$contactEmail = $settings['contact_email'] ?? 'support@featuresgym.com';
$contactPhone = $settings['contact_phone'] ?? '+91 1234567890';
$address = $settings['address'] ?? '123 Fitness Street, Mumbai, India';
$siteName = $settings['site_name'] ?? 'FeatureGym';

include 'includes/navbar.php';
?>

<!-- Hero Section -->
<section class="bg-gradient-to-r from-blue-500 to-blue-600 dark:from-blue-700 dark:to-blue-800 text-white text-center py-20 pt-28">
  <div class="container mx-auto px-4">
    <h1 class="text-4xl font-bold mb-4">Contact Us</h1>
    <p class="text-xl max-w-2xl mx-auto">We'd love to hear from you. Reach out for any inquiries or feedback about our services!</p>
  </div>
</section>

<!-- Contact Information Section -->
<section class="py-12 bg-gray-100 dark:bg-gray-900">
  <div class="max-w-6xl mx-auto px-4">
    <div class="grid md:grid-cols-3 gap-8 mb-12">
      <!-- Email -->
      <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 text-center transform transition duration-300 hover:scale-105">
        <div class="w-16 h-16 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mx-auto mb-4">
          <i class="fas fa-envelope text-2xl text-blue-500 dark:text-blue-400"></i>
        </div>
        <h3 class="text-xl font-semibold text-gray-800 dark:text-white mb-2">Email Us</h3>
        <p class="text-gray-600 dark:text-gray-300 mb-3">For general inquiries and support</p>
        <a href="mailto:<?php echo htmlspecialchars($contactEmail); ?>" class="text-blue-600 dark:text-blue-400 font-medium hover:underline">
          <?php echo htmlspecialchars($contactEmail); ?>
        </a>
      </div>
      
      <!-- Phone -->
      <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 text-center transform transition duration-300 hover:scale-105">
        <div class="w-16 h-16 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mx-auto mb-4">
          <i class="fas fa-phone-alt text-2xl text-blue-500 dark:text-blue-400"></i>
        </div>
        <h3 class="text-xl font-semibold text-gray-800 dark:text-white mb-2">Call Us</h3>
        <p class="text-gray-600 dark:text-gray-300 mb-3">Mon-Fri from 9am to 6pm</p>
        <a href="tel:<?php echo htmlspecialchars(str_replace(' ', '', $contactPhone)); ?>" class="text-blue-600 dark:text-blue-400 font-medium hover:underline">
          <?php echo htmlspecialchars($contactPhone); ?>
        </a>
      </div>
      
      <!-- Location -->
      <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 text-center transform transition duration-300 hover:scale-105">
        <div class="w-16 h-16 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mx-auto mb-4">
          <i class="fas fa-map-marker-alt text-2xl text-blue-500 dark:text-blue-400"></i>
        </div>
        <h3 class="text-xl font-semibold text-gray-800 dark:text-white mb-2">Visit Us</h3>
        <p class="text-gray-600 dark:text-gray-300 mb-3">Our headquarters</p>
        <address class="text-blue-600 dark:text-blue-400 font-medium not-italic">
          <?php echo htmlspecialchars($address); ?>
        </address>
      </div>
    </div>

    <!-- Contact Form Section -->
    <div class="bg-white dark:bg-gray-800 shadow-md rounded-lg overflow-hidden">
      <div class="p-6 bg-blue-50 dark:bg-blue-900 border-b border-blue-100 dark:border-blue-800">
        <h2 class="text-2xl font-bold text-gray-700 text-center">Get in Touch</h2>
        <p class="text-center text-gray-600 dark:text-gray-300 mt-2">
          Fill out the form below and we'll get back to you as soon as possible.
        </p>
      </div>
      
      <form action="contact-form.php" method="POST" class="p-6">
        <div class="grid gap-6 md:grid-cols-2">
          <div>
            <label for="name" class="block text-gray-700 dark:text-gray-300 mb-2 font-medium">Full Name</label>
            <input type="text" id="name" name="name" required 
                  class="w-full p-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
          </div>
          
          <div>
            <label for="email" class="block text-gray-700 dark:text-gray-300 mb-2 font-medium">Email Address</label>
            <input type="email" id="email" name="email" required 
                  class="w-full p-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
          </div>
        </div>

        <div class="mt-6">
          <label for="subject" class="block text-gray-700 dark:text-gray-300 mb-2 font-medium">Subject</label>
          <input type="text" id="subject" name="subject" required 
                class="w-full p-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
        </div>

        <div class="mt-6">
          <label for="message" class="block text-gray-700 dark:text-gray-300 mb-2 font-medium">Your Message</label>
          <textarea id="message" name="message" rows="5" required 
                    class="w-full p-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-white"></textarea>
        </div>

        <div class="mt-8 text-center">
          <button type="submit" class="bg-blue-600 hover:bg-blue-700 dark:bg-blue-700 dark:hover:bg-blue-800 text-white py-3 px-8 rounded-lg font-medium transition duration-300 transform hover:-translate-y-1 shadow-md">
            <i class="fas fa-paper-plane mr-2"></i> Send Message
          </button>
        </div>
      </form>
    </div>
  </div>
</section>

<!-- FAQ Section -->
<section class="py-12 bg-gray-50 dark:bg-gray-800">
  <div class="max-w-4xl mx-auto px-4">
    <h2 class="text-3xl font-bold text-center text-gray-700 mb-12">Frequently Asked Questions</h2>
    
    <div class="space-y-6">
      <!-- FAQ Item 1 -->
      <div class="bg-white dark:bg-gray-700 rounded-lg shadow-md overflow-hidden">
        <button class="faq-toggle flex justify-between items-center w-full p-5 text-left font-medium text-gray-800 dark:text-white focus:outline-none">
          <span>How does the multi-gym access work?</span>
          <i class="fas fa-chevron-down text-blue-500 transition-transform duration-300"></i>
        </button>
        <div class="faq-content hidden px-5 pb-5 text-gray-600 dark:text-gray-300">
          <p>Our platform gives you access to multiple gyms with a single membership. You can book sessions at any of our partner gyms through our website or mobile app. Your membership is valid across all locations in our network.</p>
        </div>
      </div>
      
      <!-- FAQ Item 2 -->
      <div class="bg-white dark:bg-gray-700 rounded-lg shadow-md overflow-hidden">
        <button class="faq-toggle flex justify-between items-center w-full p-5 text-left font-medium text-gray-800 dark:text-white focus:outline-none">
          <span>What happens if I need to cancel a booking?</span>
          <i class="fas fa-chevron-down text-blue-500 transition-transform duration-300"></i>
        </button>
        <div class="faq-content hidden px-5 pb-5 text-gray-600 dark:text-gray-300">
          <p>You can cancel a booking up to 4 hours before the scheduled time. When you cancel, we automatically extend your membership validity, ensuring you don't lose out on any sessions you've paid for.</p>
        </div>
      </div>
      
      <!-- FAQ Item 3 -->
      <div class="bg-white dark:bg-gray-700 rounded-lg shadow-md overflow-hidden">
        <button class="faq-toggle flex justify-between items-center w-full p-5 text-left font-medium text-gray-800 dark:text-white focus:outline-none">
          <span>How do I become a partner gym?</span>
          <i class="fas fa-chevron-down text-blue-500 transition-transform duration-300"></i>
        </button>
        <div class="faq-content hidden px-5 pb-5 text-gray-600 dark:text-gray-300">
          <p>If you own a gym and would like to join our network, please contact us through this form or email us directly at <?php echo htmlspecialchars($contactEmail); ?>. We'll provide you with information about our partnership program and the onboarding process.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Map Section -->
<section class="py-12 bg-white dark:bg-gray-900">
  <div class="max-w-6xl mx-auto px-4">
    <h2 class="text-2xl font-bold text-center text-gray-800 dark:text-white mb-8">Find Us</h2>
    <div class="h-96 bg-gray-200 dark:bg-gray-700 rounded-lg overflow-hidden shadow-md">
      <!-- Replace with actual Google Maps embed code -->
      <div class="w-full h-full flex items-center justify-center bg-gray-200 dark:bg-gray-700">
        <p class="text-gray-500 dark:text-gray-400">
          <i class="fas fa-map-marked-alt text-4xl mb-3"></i><br>
          Google Maps will be displayed here.<br>
          <span class="text-sm">(API key required in production)</span>
        </p>
      </div>
    </div>
  </div>
</section>

<script>
  // FAQ Toggle Functionality
  document.addEventListener('DOMContentLoaded', function() {
    const faqToggles = document.querySelectorAll('.faq-toggle');
    
    faqToggles.forEach(toggle => {
      toggle.addEventListener('click', function() {
        const content = this.nextElementSibling;
        const icon = this.querySelector('i');
        
        // Toggle the content visibility
        if (content.classList.contains('hidden')) {
          content.classList.remove('hidden');
          icon.classList.add('rotate-180');
        } else {
          content.classList.add('hidden');
          icon.classList.remove('rotate-180');
        }
      });
    });
  });
</script>

<?php include 'includes/footer.php'; ?>
