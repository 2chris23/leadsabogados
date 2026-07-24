<?php
// Serve Service Worker as JS via PHP
header('Content-Type: application/javascript');
header('Service-Worker-Allowed: /');
header('Cache-Control: no-cache');
?>
// Service Worker — Portal del Cliente
const CACHE_NAME = 'portal-cliente-v1';
const OFFLINE_URL = 'offline.html';

const PRECACHE_ASSETS = [
  'offline.html'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE_ASSETS))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;
  if (new URL(request.url).origin !== location.origin) return;

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((res) => {
          const clone = res.clone();
          caches.open(CACHE_NAME).then((c) => c.put(request, clone));
          return res;
        })
        .catch(() => caches.match(request).then((c) => c || caches.match(OFFLINE_URL)))
    );
    return;
  }

  if (request.url.match(/\.(css|js|png|jpg|svg|woff2?)$/)) {
    event.respondWith(
      caches.match(request).then((cached) => {
        if (cached) return cached;
        return fetch(request).then((res) => {
          const clone = res.clone();
          caches.open(CACHE_NAME).then((c) => c.put(request, clone));
          return res;
        });
      })
    );
  }
});
