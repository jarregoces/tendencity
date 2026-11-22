/* Tendencity — interacción mínima, sin dependencias */
(() => {
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const money = (n) => '$' + Math.round(n).toLocaleString('es-CO');

  // Cerrar el menú móvil al tocar fuera
  const drawer = $('.drawer');
  document.addEventListener('click', (e) => { if (drawer?.open && !drawer.contains(e.target)) drawer.open = false; });

  // Galería: miniaturas
  const gallery = $('#gallery');
  $$('.thumbs button').forEach((b, i, all) => {
    b.addEventListener('click', () => {
      gallery.scrollTo({ left: gallery.clientWidth * i, behavior: 'smooth' });
      all.forEach((x) => x.classList.toggle('is-on', x === b));
    });
  });

  // Contadores en cajas (banners y oferta del día)
  $$('[data-countdown]').forEach((el) => {
    const end = new Date(el.dataset.countdown).getTime();
    const pad = (n) => String(n).padStart(2, '0');
    const tick = () => {
      const s = Math.max(0, Math.floor((end - Date.now()) / 1000));
      const v = { d: Math.floor(s / 86400), h: Math.floor(s % 86400 / 3600), m: Math.floor(s % 3600 / 60), s: s % 60 };
      for (const k in v) { const b = el.querySelector(`[data-u="${k}"]`); if (b) b.textContent = pad(v[k]); }
      if (s > 0) setTimeout(tick, 1000);
    };
    tick();
  });

  // Pestañas de productos populares
  $$('.tabs').forEach((tabs) => {
    const btns = $$('.tabs__b', tabs);
    btns.forEach((b) => b.addEventListener('click', () => {
      btns.forEach((x) => { const on = x === b; x.classList.toggle('is-on', on); x.setAttribute('aria-selected', on);
        const p = document.getElementById(x.getAttribute('aria-controls')); if (p) p.hidden = !on; });
    }));
  });

  // Menú "Todas las categorías"
  const allBtn = $('.allcats__btn');
  if (allBtn) {
    const box = allBtn.parentElement;
    allBtn.addEventListener('click', (e) => { e.stopPropagation(); const o = box.classList.toggle('is-open'); allBtn.setAttribute('aria-expanded', o); });
    document.addEventListener('click', (e) => { if (!box.contains(e.target)) { box.classList.remove('is-open'); allBtn.setAttribute('aria-expanded', 'false'); } });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') box.classList.remove('is-open'); });
  }

  // Contador de oferta (solo existe si hay fecha real de fin)
  $$('.countdown[data-until]').forEach((el) => {
    const end = new Date(el.dataset.until).getTime();
    const tick = () => {
      const s = Math.max(0, Math.floor((end - Date.now()) / 1000));
      if (s === 0) { el.textContent = 'La oferta terminó'; return; }
      const d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
      el.textContent = 'La oferta termina en ' + (d ? d + ' d ' : '') + h + ' h ' + m + ' min';
      setTimeout(tick, 30000);
    };
    tick();
  });

  // Barra fija de compra: aparece cuando el botón principal sale de la pantalla
  const sticky = $('.sticky-buy');
  const mainCta = $('.pdp__info [data-open-order]');
  if (sticky && mainCta && 'IntersectionObserver' in window) {
    new IntersectionObserver(([en]) => {
      const on = !en.isIntersecting && en.boundingClientRect.top < 0;
      sticky.classList.toggle('is-on', on);
      sticky.setAttribute('aria-hidden', on ? 'false' : 'true');
      sticky.querySelector('button').tabIndex = on ? 0 : -1;
    }).observe(mainCta);
  }

  /* ---------- Selector de color en la ficha ---------- */
  const colorBtns = $$('.colors__b');
  colorBtns.forEach((b) => b.addEventListener('click', () => {
    colorBtns.forEach((x) => { const on = x === b; x.classList.toggle('is-on', on); x.setAttribute('aria-checked', on); });
    const name = $('[data-color-name]'); if (name) name.textContent = b.dataset.name;
    if (b.dataset.slide !== undefined && gallery) {
      gallery.scrollTo({ left: gallery.clientWidth * Number(b.dataset.slide), behavior: 'smooth' });
      $$('.thumbs button').forEach((t, i) => t.classList.toggle('is-on', i === Number(b.dataset.slide)));
    }
    const radio = document.querySelector(`#order [name=variant_id][value="${b.dataset.variant}"]`);
    if (radio) { radio.checked = true; radio.dispatchEvent(new Event('change', { bubbles: true })); }
  }));

  /* ---------- Formulario de pedido ---------- */
  const dlg = $('#order');
  if (!dlg) return;
  const form = $('form', dlg);
  const cfg = JSON.parse(form.dataset.cfg);
  const err = $('.order__error', form);
  const submit = $('[data-submit]', form);

  // Misma regla que Pricing::discounted en PHP (redondeo a $100 hacia abajo)
  const disc = (price, pct) => pct ? Math.floor(price * (100 - pct) / 100 / 100) * 100 : price;

  const current = () => {
    const vId = Number((form.querySelector('[name=variant_id]:checked') || form.querySelector('[name=variant_id]'))?.value || 0);
    const v = cfg.variants.find((x) => x.id === vId);
    const base = v && v.price ? v.price : cfg.price;
    const qty = Number((form.querySelector('[name=qty]:checked') || form.querySelector('[name=qty]')).value);
    const method = (form.querySelector('[name=payment_method]:checked') || form.querySelector('[name=payment_method]')).value;
    return { base, qty, method };
  };

  const update = () => {
    const { base, qty, method } = current();
    const extra = method === 'pia' ? cfg.piaPct : 0;
    cfg.offers.forEach((o) => {
      const el = form.querySelector(`[data-offer-price="${o.qty}"]`);
      if (el) el.textContent = money(disc(base, Math.min(60, o.pct + extra)) * o.qty);
    });
    const offer = cfg.offers.find((o) => o.qty === qty) || { pct: 0 };
    const sub = disc(base, Math.min(60, offer.pct + extra)) * qty;
    const ship = cfg.freeFrom > 0 && sub >= cfg.freeFrom ? 0 : cfg.flat;
    $('[data-sum=sub]', form).textContent = money(sub);
    $('[data-sum=ship]', form).textContent = ship ? money(ship) : 'Incluido a toda Colombia';
    $('[data-sum=total]', form).textContent = money(sub + ship);
  };
  form.addEventListener('change', update);
  update();

  const open = () => {
    dlg.showModal();
    navigator.sendBeacon?.('/evento', new Blob([JSON.stringify({ type: 'form_open', product_id: cfg.productId })], { type: 'application/json' }));
    if (!open.sent) { // una vez por página
      open.sent = true;
      const value = Number(String($('[data-sum=total]', form)?.textContent || '').replace(/\D/g, '')) || cfg.price;
      window.fbq?.('track', 'InitiateCheckout', { value, currency: 'COP', content_ids: [cfg.msId], content_type: 'product' });
      window.ttq?.track('InitiateCheckout', { value, currency: 'COP' });
      if (window.gtag) gtag('event', 'begin_checkout', { value, currency: 'COP' }); else window.dataLayer?.push({ event: 'begin_checkout', ecommerce: { value, currency: 'COP' } });
    }
  };
  $$('[data-open-order]').forEach((b) => b.addEventListener('click', open));
  // Desde las tarjetas ("Pedir ahora") se llega con #pedir: abre el formulario directo
  if (location.hash === '#pedir') { history.replaceState(null, '', location.pathname + location.search); setTimeout(open, 250); }
  $('[data-close]', dlg).addEventListener('click', () => dlg.close());
  dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });

  const showError = (msg, field) => {
    err.textContent = msg;
    err.hidden = false;
    $$('.field.is-bad', form).forEach((f) => f.classList.remove('is-bad'));
    const input = field && form.querySelector(`[name="${field}"]`);
    if (input) { input.closest('.field')?.classList.add('is-bad'); input.focus(); }
    else err.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  };

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    err.hidden = true;
    // Validación en el navegador: primero el campo que falte
    const phone = form.phone;
    phone.value = phone.value.replace(/\D/g, '').slice(-10);
    for (const input of $$('input[required], select[required]', form)) {
      if (!input.checkValidity()) {
        const label = input.closest('.field')?.querySelector('span')?.textContent.trim() || 'Este campo';
        return showError(input === phone ? 'Escribe un celular de 10 dígitos que empiece por 3.' : `Completa: ${label}.`, input.name);
      }
    }
    submit.disabled = true;
    submit.textContent = 'Enviando pedido…';
    try {
      const res = await fetch(form.action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(Object.fromEntries(new FormData(form))),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) throw Object.assign(new Error(data.error || 'No pudimos registrar tu pedido. Intenta de nuevo.'), { field: data.field });
      location.href = data.redirect;
    } catch (ex) {
      showError(ex.message, ex.field);
      submit.disabled = false;
      submit.textContent = 'Confirmar pedido';
    }
  });
})();
