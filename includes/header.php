<!DOCTYPE html>
<html lang="en" data-default-theme="<?= htmlspecialchars($site_settings['theme']['default_theme'] ?? 'dark') ?>" 
      data-allow-user-theme="<?= htmlspecialchars($site_settings['theme']['allow_user_theme'] ?? '1') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site_settings['seo']['site_title'] ?? 'Features Gym - Find Your Perfect Gym') ?></title>
    
    <!-- Meta tags -->
    <meta name="description" content="<?= htmlspecialchars($site_settings['seo']['meta_description'] ?? '') ?>">
    <meta name="keywords" content="<?= htmlspecialchars($site_settings['seo']['meta_keywords'] ?? '') ?>">
    <meta name="author" content="<?= htmlspecialchars($site_settings['seo']['meta_author'] ?? 'Features Gym') ?>">
    
    <!-- Favicon -->
    <link rel="icon" href="<?= $base_url ?>/<?= htmlspecialchars($site_settings['appearance']['favicon_path'] ?? 'assets/images/favicon.ico') ?>">
    <link rel="apple-touch-icon" href="<?= $base_url ?>/<?= htmlspecialchars($site_settings['appearance']['apple_touch_icon'] ?? 'assets/images/apple-touch-icon.png') ?>">
    
    <!-- Open Graph / Social Media Meta Tags -->
    <meta property="og:title" content="<?= htmlspecialchars($site_settings['seo']['og_title'] ?? $site_settings['seo']['site_title'] ?? 'Features Gym') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($site_settings['seo']['og_description'] ?? $site_settings['seo']['meta_description'] ?? '') ?>">
    <meta property="og:image" content="<?= $base_url ?>/<?= htmlspecialchars($site_settings['seo']['og_image'] ?? 'assets/images/og-image.jpg') ?>">
    <meta property="og:url" content="<?= htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]") ?>">
    <meta property="og:type" content="website">
    
    <!-- Theme CSS -->
    <link rel="stylesheet" href="<?= $base_url ?>/assets/css/theme-variables.css">
    
    <!-- Tailwind CSS -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <!-- Custom CSS -->
    <style>
        body {
            background-color: var(--bg-primary);
            color: var(--text-primary);
            font-family: <?= htmlspecialchars($site_settings['appearance']['font_family'] ?? 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif') ?>;
        }
        
        /* Add custom CSS from database if available */
        <?= $site_settings['custom']['custom_css'] ?? '' ?>
    </style>
    
    <!-- Theme Switcher Script -->
    <script src="<?= $base_url ?>/assets/js/theme-switcher.js"></script>
    
    <!-- Google Analytics if configured -->
    <?php if (!empty($site_settings['seo']['google_analytics'])): ?>
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?= htmlspecialchars($site_settings['seo']['google_analytics']) ?>"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '<?= htmlspecialchars($site_settings['seo']['google_analytics']) ?>');
    </script>
    <?php endif; ?>
    
    <!-- Google Tag Manager if configured -->
    <?php if (!empty($site_settings['seo']['google_tag_manager'])): ?>
    <script>
        (function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
        new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
        j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
        'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
        })(window,document,'script','dataLayer','<?= htmlspecialchars($site_settings['seo']['google_tag_manager']) ?>');
    </script>
    <?php endif; ?>
    
    <!-- Facebook Pixel if configured -->
    <?php if (!empty($site_settings['seo']['facebook_pixel'])): ?>
    <script>
        !function(f,b,e,v,n,t,s)
        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window, document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', '<?= htmlspecialchars($site_settings['seo']['facebook_pixel']) ?>');
        fbq('track', 'PageView');
    </script>
    <noscript>
        <img height="1" width="1" style="display:none" 
             src="https://www.facebook.com/tr?id=<?= htmlspecialchars($site_settings['seo']['facebook_pixel']) ?>&ev=PageView&noscript=1"/>
    </noscript>
    <?php endif; ?>
    
    <!-- Custom tracking code if configured -->
    <?php if (!empty($site_settings['seo']['custom_tracking_code'])): ?>
        <?= $site_settings['seo']['custom_tracking_code'] ?>
    <?php endif; ?>
    
    <!-- CSRF Token for AJAX requests -->
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    
    <!-- Localization settings for JavaScript -->
    <script>
        window.appSettings = {
            baseUrl: '<?= $base_url ?>',
            currency: '<?= htmlspecialchars($site_settings['financial']['currency'] ?? 'INR') ?>',
            currencySymbol: '<?= htmlspecialchars($site_settings['financial']['currency_symbol'] ?? '₹') ?>',
            dateFormat: '<?= htmlspecialchars($site_settings['localization']['date_format'] ?? 'Y-m-d') ?>',
            timeFormat: '<?= htmlspecialchars($site_settings['localization']['time_format'] ?? 'H:i') ?>',
            distanceUnit: '<?= htmlspecialchars($site_settings['localization']['distance_unit'] ?? 'km') ?>',
            defaultLatitude: <?= floatval($site_settings['maps']['default_latitude'] ?? 20.5937) ?>,
            defaultLongitude: <?= floatval($site_settings['maps']['default_longitude'] ?? 78.9629) ?>,
            defaultZoom: <?= intval($site_settings['maps']['default_zoom'] ?? 5) ?>,
            language: '<?= htmlspecialchars($site_settings['localization']['default_language'] ?? 'en') ?>'
        };
    </script>
</head>
<body class="theme-transition">
    <!-- Google Tag Manager (noscript) if configured -->
    <?php if (!empty($site_settings['seo']['google_tag_manager'])): ?>
    <noscript>
        <iframe src="https://www.googletagmanager.com/ns.html?id=<?= htmlspecialchars($site_settings['seo']['google_tag_manager']) ?>" height="0" width="0" style="display:none;visibility:hidden"></iframe>
    </noscript>
    <?php endif; ?>
    
    <!-- Maintenance mode notice for non-admin users -->
    <?php if (isset($site_settings['general']['maintenance_mode']) && $site_settings['general']['maintenance_mode'] == '1' && (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin')): ?>
    <div class="fixed inset-0 bg-black bg-opacity-75 z-50 flex items-center justify-center">
        <div class="bg-gray-800 p-8 rounded-lg max-w-md text-center">
            <i class="fas fa-tools text-yellow-500 text-5xl mb-4"></i>
            <h2 class="text-2xl font-bold text-white mb-2">Site Under Maintenance</h2>
            <p class="text-gray-300 mb-4"><?= htmlspecialchars($site_settings['general']['maintenance_message'] ?? 'We are currently performing scheduled maintenance. Please check back soon.') ?></p>
            <?php if (!empty($site_settings['general']['maintenance_contact'])): ?>
            <p class="text-gray-400 text-sm">For urgent inquiries: <?= htmlspecialchars($site_settings['general']['maintenance_contact']) ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Cookie consent banner if enabled -->
    <?php if (isset($site_settings['privacy']['show_cookie_consent']) && $site_settings['privacy']['show_cookie_consent'] == '1' && !isset($_COOKIE['cookie_consent'])): ?>
    <div id="cookie-consent-banner" class="fixed bottom-0 left-0 right-0 bg-gray-800 p-4 z-40">
        <div class="container mx-auto flex flex-col md:flex-row items-center justify-between">
            <div class="text-white text-sm mb-4 md:mb-0">
                <?= htmlspecialchars($site_settings['privacy']['cookie_consent_text'] ?? 'This website uses cookies to ensure you get the best experience on our website.') ?>
                <a href="<?= $base_url ?>/privacy-policy.php" class="text-yellow-400 hover:underline">Learn more</a>
            </div>
            <div class="flex space-x-4">
                <button id="cookie-accept" class="bg-yellow-500 hover:bg-yellow-600 text-black px-4 py-2 rounded text-sm font-medium">Accept</button>
                <button id="cookie-decline" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded text-sm font-medium">Decline</button>
            </div>
        </div>
    </div>
    <script>
        document.getElementById('cookie-accept').addEventListener('click', function() {
            setCookieConsent(true);
            document.getElementById('cookie-consent-banner').style.display = 'none';
        });
        
        document.getElementById('cookie-decline').addEventListener('click', function() {
            setCookieConsent(false);
            document.getElementById('cookie-consent-banner').style.display = 'none';
        });
        
        function setCookieConsent(accepted) {
            const value = accepted ? 'accepted' : 'declined';
            const date = new Date();
            date.setTime(date.getTime() + (365 * 24 * 60 * 60 * 1000)); // 1 year
            document.cookie = "cookie_consent=" + value + "; expires=" + date.toUTCString() + "; path=/; SameSite=Lax";
            
            // Track consent if analytics is available and consent was accepted
            if (accepted && typeof gtag === 'function') {
                gtag('consent', 'update', {
                    'analytics_storage': 'granted'
                });
            }
        }
    </script>
    <?php endif; ?>
    
    <!-- Rest of your body content -->
