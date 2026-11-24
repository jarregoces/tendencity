// Service worker mínimo: permite instalar la app y mostrar avisos de venta.
// No guarda nada en caché: los datos de ventas siempre se piden en vivo.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {});
self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  e.waitUntil(self.clients.matchAll({ type: 'window' }).then((list) => {
    for (const c of list) { if (c.url.includes('/admin/ventas.php')) return c.focus(); }
    return self.clients.openWindow('/admin/ventas.php');
  }));
});
