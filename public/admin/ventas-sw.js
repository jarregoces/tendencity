// Service worker de la app de ventas: permite instalarla y recibir avisos de venta
// aunque la app esté cerrada. No guarda nada en caché: los datos siempre se piden en vivo.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {});

// La tienda manda un aviso vacío; aquí se piden los datos con la sesión del dueño y se arma el mensaje.
self.addEventListener('push', (e) => {
  e.waitUntil((async () => {
    let title = '¡Nueva venta! 🛎️';
    let body = 'Toca para ver tus ventas de hoy';
    try {
      const r = await fetch('/admin/ventas.php?data=1', { credentials: 'include', cache: 'no-store' });
      if (r.ok) {
        const d = await r.json();
        const money = (n) => (d.sym || 'Q') + Math.round(n).toLocaleString(d.loc || 'es-GT');
        const o = d.orders && d.orders[0];
        if (o) {
          title = `¡Nueva venta! ${money(o.total)} 🛎️`;
          body = `${o.name} · ${o.items}\nHoy: ${money(d.total)} en ${d.count} pedido${d.count === 1 ? '' : 's'}`;
        }
      }
    } catch (err) { /* sin datos: se muestra el aviso genérico */ }
    await self.registration.showNotification(title, {
      body,
      icon: '/admin/ventas-192.png',
      badge: '/admin/ventas-192.png',
      tag: 'venta',
      renotify: true,
      vibrate: [200, 100, 200, 100, 400],
    });
    // Si la app está abierta, que se actualice al instante y suene la caja registradora
    const open = await self.clients.matchAll({ type: 'window' });
    open.forEach((c) => c.postMessage({ type: 'venta' }));
  })());
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  e.waitUntil(self.clients.matchAll({ type: 'window' }).then((list) => {
    for (const c of list) { if (c.url.includes('/admin/ventas.php')) return c.focus(); }
    return self.clients.openWindow('/admin/ventas.php');
  }));
});
