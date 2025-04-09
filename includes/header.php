<!DOCTYPE html>
<html lang="en" data-default-theme="<?= htmlspecialchars($site_settings['theme']['default_theme'] ?? 'dark') ?>" 
      data-allow-user-theme="<?= htmlspecialchars($site_settings['theme']['allow_user_theme'] ?? '1') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site_settings['seo']['site_title'] ?? 'ProFitMart - Find Your Perfect Gym') ?></title>
    
    <!-- Meta tags -->
    <meta name="description" content="<?= htmlspecialchars($site_settings['seo']['meta_description'] ?? '') ?>">
    <meta name="keywords" content="<?= htmlspecialchars($site_settings['seo']['meta_keywords'] ?? '') ?>">
    
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
</head>
<body class="theme-transition">
    <!-- Rest of your body content -->
