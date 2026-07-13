/* Minimální service worker Fix-CRM — existuje kvůli instalovatelnosti PWA
   („Přidat na plochu"). ZÁMĚRNĚ nic necachuje: CRM musí vždy ukazovat živá
   data ze serveru, offline režim nedává u zakázek smysl. */
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });
self.addEventListener('fetch', function () { /* výchozí síťové chování prohlížeče */ });
