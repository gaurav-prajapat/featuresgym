<?php
// This file generates preload headers for critical resources

function generatePreloadHeaders() {
    $criticalResources = [
        '/assets/css/main.css' => 'style',
        '/assets/js/main.js' => 'script',
        '/assets/images/logo.png' => 'image',
        'https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css' => 'style',
        'https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js' => 'script'
    ];
    
    $headers = [];
    foreach ($criticalResources as $url => $type) {
        $headers[] = "<{$url}>; rel=preload; as={$type}";
    }
    
    if (!empty($headers)) {
        header('Link: ' . implode(', ', $headers));
    }
}

// Call this function early in your page load
generatePreloadHeaders();
