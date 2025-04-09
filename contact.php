<?php
include 'includes/navbar.php';
?>
  <!-- Hero Section -->
  <section class="bg-blue-500 text-white text-center py-16">
    <h1 class="text-3xl font-semibold">Contact Us</h1>
    <p class="mt-4">We'd love to hear from you. Reach out for any inquiries or feedback!</p>
  </section>

  <!-- Contact Form Section -->
  <section class="max-w-6xl mx-auto p-8">
    <div class="bg-white shadow-md rounded-lg p-6">
      <h2 class="text-2xl font-semibold text-center mb-6">Get in Touch</h2>
      <form action="contact-form.php" method="POST">
        <div class="grid gap-4 md:grid-cols-2">
          <div>
            <label for="name" class="block text-gray-700">Full Name</label>
            <input type="text" id="name" name="name" required class="w-full p-3 border border-gray-300 rounded-lg mt-2">
          </div>
          <div>
            <label for="email" class="block text-gray-700">Email Address</label>
            <input type="email" id="email" name="email" required class="w-full p-3 border border-gray-300 rounded-lg mt-2">
          </div>
        </div>

        <div class="mt-4">
          <label for="message" class="block text-gray-700">Your Message</label>
          <textarea id="message" name="message" rows="4" required class="w-full p-3 border border-gray-300 rounded-lg mt-2"></textarea>
        </div>

        <div class="mt-6 text-center">
          <button type="submit" class="bg-blue-600 text-white py-3 px-6 rounded-lg hover:bg-blue-700 transition duration-300">
            Send Message
          </button>
        </div>
      </form>
    </div>
  </section>

  <?php include 'includes/footer.php'; ?>
