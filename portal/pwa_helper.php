<?php
/**
 * Portal PWA Helper — genera las meta tags y el script de registro del SW
 * Usa archivos .php en vez de .json/.js para compatibilidad con InfinityFree
 */

function portalPwaHead() {
    $portalBase = portalUrl();
    return '
    <meta name="theme-color" content="#2e6edd">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Mi Portal">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="application-name" content="Mi Portal">
    <link rel="manifest" href="' . $portalBase . '/manifest.php">
    <link rel="apple-touch-icon" href="' . $portalBase . '/assets/icon-192.png">';
}

function portalPwaScript() {
    return '
<script>
if ("serviceWorker" in navigator) {
    navigator.serviceWorker.getRegistrations().then(function(registrations) {
        for(let registration of registrations) {
            registration.unregister();
        }
    });
}
</script>';
}
