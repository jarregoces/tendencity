<?php /** @var array $products */ if (!$products): ?>
  <p class="empty">Estamos cargando productos en esta sección. Mientras tanto, mira las <a href="/ofertas/">ofertas</a>.</p>
<?php else: ?>
  <div class="grid"><?php foreach ($products as $p) { partial('card', ['p' => $p]); } ?></div>
<?php endif; ?>
