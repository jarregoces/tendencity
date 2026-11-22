<?php
declare(strict_types=1);

/** Consultas de lectura para el frontend. Solo devuelve contenido activo/publicado. */
final class Catalog
{
    private const CARD_COLS = 'p.id, p.slug, p.name, p.title, p.brand, p.price, p.price_before, p.offer_from, p.offer_until, p.images, p.custom_images, p.badge, p.is_featured, p.stock, p.category_id';

    public function __construct(private PDO $db) {}

    /** Pilares con sus subcategorías (para menú y home). */
    public function tree(): array
    {
        $rows = $this->db->query('SELECT * FROM categories WHERE is_active = 1 ORDER BY sort, name')->fetchAll();
        $pillars = [];
        foreach ($rows as $r) {
            if ($r['parent_id'] === null) {
                $pillars[$r['id']] = $r + ['children' => []];
            }
        }
        foreach ($rows as $r) {
            if ($r['parent_id'] !== null && isset($pillars[$r['parent_id']])) {
                $pillars[$r['parent_id']]['children'][] = $r;
            }
        }
        return array_values($pillars);
    }

    public function categoryByPath(string $pillar, ?string $sub = null): ?array
    {
        $st = $this->db->prepare('SELECT * FROM categories WHERE slug = ? AND parent_id IS NULL AND is_active = 1');
        $st->execute([$pillar]);
        $p = $st->fetch();
        if (!$p || $sub === null) {
            return $p ?: null;
        }
        $st = $this->db->prepare('SELECT * FROM categories WHERE slug = ? AND parent_id = ? AND is_active = 1');
        $st->execute([$sub, $p['id']]);
        $c = $st->fetch();
        return $c ? $c + ['parent' => $p] : null;
    }

    public function category(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM categories WHERE id = ?');
        $st->execute([$id]);
        $c = $st->fetch();
        if ($c && $c['parent_id']) {
            $c['parent'] = $this->category((int) $c['parent_id']);
        }
        return $c ?: null;
    }

    public function children(int $id): array
    {
        $st = $this->db->prepare('SELECT * FROM categories WHERE parent_id = ? AND is_active = 1 ORDER BY sort, name');
        $st->execute([$id]);
        return $st->fetchAll();
    }

    public static function categoryUrl(array $c): string
    {
        return isset($c['parent']['slug'])
            ? "/{$c['parent']['slug']}/{$c['slug']}/"
            : "/{$c['slug']}/";
    }

    /** Productos de una categoría; si es pilar incluye los de sus subcategorías. */
    private function catIds(array $cat): string
    {
        $ids = [(int) $cat['id']];
        if ($cat['parent_id'] === null) {
            $ids = array_merge($ids, array_map('intval', array_column($this->children((int) $cat['id']), 'id')));
        }
        return implode(',', $ids);
    }

    public function productsIn(array $cat, int $limit = 48, int $offset = 0): array
    {
        return $this->db->query(
            'SELECT ' . self::CARD_COLS . " FROM products p
             WHERE p.is_active = 1 AND p.category_id IN ({$this->catIds($cat)})
             ORDER BY p.stock > 0 DESC, p.is_featured DESC, p.sort, p.id DESC LIMIT " . (int) $limit . ' OFFSET ' . max(0, $offset)
        )->fetchAll();
    }

    public function countIn(array $cat): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM products p WHERE p.is_active = 1 AND p.category_id IN ({$this->catIds($cat)})")->fetchColumn();
    }

    public function product(string $slug): ?array
    {
        $st = $this->db->prepare('SELECT * FROM products WHERE slug = ? AND is_active = 1');
        $st->execute([$slug]);
        return $st->fetch() ?: null;
    }

    public function productById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT ' . self::CARD_COLS . ' FROM products p WHERE id = ? AND is_active = 1');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function variants(int $productId): array
    {
        $st = $this->db->prepare('SELECT * FROM product_variants WHERE product_id = ? ORDER BY id');
        $st->execute([$productId]);
        return $st->fetchAll();
    }

    /** Hermanos de la misma subcategoría (enlazado lateral dentro del silo). */
    public function related(array $p, int $limit = 4): array
    {
        if (!$p['category_id']) {
            return [];
        }
        $st = $this->db->prepare(
            'SELECT ' . self::CARD_COLS . ' FROM products p
             WHERE p.is_active = 1 AND p.category_id = ? AND p.id <> ?
             ORDER BY p.stock > 0 DESC, RAND() LIMIT ' . (int) $limit
        );
        $st->execute([$p['category_id'], $p['id']]);
        return $st->fetchAll();
    }

    public function productsByIds(array $ids): array
    {
        $ids = array_filter(array_map('intval', $ids));
        if (!$ids) {
            return [];
        }
        $in = implode(',', $ids);
        return $this->db->query('SELECT ' . self::CARD_COLS . " FROM products p WHERE p.is_active = 1 AND p.id IN ({$in}) ORDER BY FIELD(p.id, {$in})")->fetchAll();
    }

    public function featured(int $limit = 8): array
    {
        return $this->db->query(
            'SELECT ' . self::CARD_COLS . ' FROM products p WHERE p.is_active = 1 AND p.stock > 0
             ORDER BY p.is_featured DESC, p.sort, p.id DESC LIMIT ' . (int) $limit
        )->fetchAll();
    }

    private const OFFER_WHERE = 'p.is_active = 1 AND p.price_before > p.price AND (p.offer_until IS NULL OR p.offer_until > NOW())
               AND (p.offer_from IS NULL OR p.offer_from <= NOW())';

    public function onOffer(int $limit = 48, int $offset = 0): array
    {
        return $this->db->query(
            'SELECT ' . self::CARD_COLS . ' FROM products p WHERE ' . self::OFFER_WHERE . '
             ORDER BY (p.price_before - p.price) / p.price_before DESC LIMIT ' . (int) $limit . ' OFFSET ' . max(0, $offset)
        )->fetchAll();
    }

    public function countOffers(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM products p WHERE ' . self::OFFER_WHERE)->fetchColumn();
    }

    /* ---------- Reseñas ---------- */

    public function reviews(?int $pillarId = null, int $limit = 30): array
    {
        $sql = 'SELECT r.*, c.slug AS cat_slug, c.name AS cat_name FROM reviews r
                LEFT JOIN categories c ON c.id = r.category_id
                WHERE r.is_published = 1';
        $args = [];
        if ($pillarId) {
            $sql .= ' AND r.category_id = ?';
            $args[] = $pillarId;
        }
        $st = $this->db->prepare($sql . ' ORDER BY r.published_at DESC LIMIT ' . (int) $limit);
        $st->execute($args);
        return $st->fetchAll();
    }

    /** Sub-pilares de reseñas = pilares de categorías que tienen al menos una reseña. */
    public function reviewPillars(): array
    {
        return $this->db->query(
            'SELECT c.*, (SELECT COUNT(*) FROM reviews r WHERE r.category_id = c.id AND r.is_published = 1) AS n
             FROM categories c
             WHERE c.parent_id IS NULL AND EXISTS (SELECT 1 FROM reviews r WHERE r.category_id = c.id AND r.is_published = 1)
             ORDER BY c.sort'
        )->fetchAll();
    }

    public function review(string $pillar, string $slug): ?array
    {
        $st = $this->db->prepare(
            'SELECT r.*, c.slug AS cat_slug, c.name AS cat_name FROM reviews r
             JOIN categories c ON c.id = r.category_id
             WHERE r.slug = ? AND c.slug = ? AND r.is_published = 1'
        );
        $st->execute([$slug, $pillar]);
        return $st->fetch() ?: null;
    }

    public function reviewForProduct(int $productId): ?array
    {
        $st = $this->db->prepare(
            'SELECT r.slug, r.title, r.rating, c.slug AS cat_slug FROM reviews r
             JOIN categories c ON c.id = r.category_id
             WHERE r.product_id = ? AND r.is_published = 1 LIMIT 1'
        );
        $st->execute([$productId]);
        return $st->fetch() ?: null;
    }

    /* ---------- Guías ---------- */

    public function guides(int $limit = 30): array
    {
        return $this->db->query(
            'SELECT g.*, c.slug AS cat_slug, c.name AS cat_name FROM guides g
             LEFT JOIN categories c ON c.id = g.category_id
             WHERE g.is_published = 1 ORDER BY g.published_at DESC LIMIT ' . (int) $limit
        )->fetchAll();
    }

    public function guide(string $slug): ?array
    {
        $st = $this->db->prepare('SELECT * FROM guides WHERE slug = ? AND is_published = 1');
        $st->execute([$slug]);
        return $st->fetch() ?: null;
    }

    /* ---------- Portada ---------- */

    public function banners(string $slot): array
    {
        $st = $this->db->prepare('SELECT * FROM banners WHERE slot = ? AND is_active = 1 AND (until IS NULL OR until > NOW()) ORDER BY sort, id');
        $st->execute([$slot]);
        return $st->fetchAll();
    }

    /** Productos publicados por pilar (incluye subcategorías), para la lista lateral. */
    public function pillarCounts(): array
    {
        return $this->db->query(
            'SELECT c.id, COUNT(p.id) n FROM categories c
             LEFT JOIN categories s ON s.parent_id = c.id
             LEFT JOIN products p ON p.is_active = 1 AND (p.category_id = c.id OR p.category_id = s.id)
             WHERE c.parent_id IS NULL GROUP BY c.id'
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** Oferta del día: la oferta activa que termina primero (con fecha real de fin). */
    public function dealOfTheDay(): ?array
    {
        return $this->db->query(
            'SELECT ' . self::CARD_COLS . ' FROM products p
             WHERE p.is_active = 1 AND p.stock > 0 AND p.price_before > p.price
               AND p.offer_until > NOW() AND (p.offer_from IS NULL OR p.offer_from <= NOW())
             ORDER BY p.offer_until LIMIT 1'
        )->fetch() ?: null;
    }

    /** Búsqueda simple por nombre, título y gancho. */
    /** @return array{0: string, 1: array} WHERE y argumentos de una búsqueda */
    private function searchWhere(string $q): ?array
    {
        $q = trim(mb_substr($q, 0, 80));
        if (mb_strlen($q) < 2) return null;
        $words = array_slice(array_filter(preg_split('/\s+/', $q)), 0, 5);
        $where = implode(' AND ', array_fill(0, count($words), '(p.name LIKE ? OR p.title LIKE ? OR p.aida_hook LIKE ? OR p.brand LIKE ?)'));
        $args = [];
        foreach ($words as $w) {
            $like = '%' . addcslashes($w, '%_\\') . '%';
            array_push($args, $like, $like, $like, $like);
        }
        return ["p.is_active = 1 AND {$where}", $args];
    }

    public function search(string $q, int $limit = 48, int $offset = 0): array
    {
        if (!($w = $this->searchWhere($q))) return [];
        $st = $this->db->prepare('SELECT ' . self::CARD_COLS . " FROM products p WHERE {$w[0]}
                                  ORDER BY p.stock > 0 DESC, p.is_featured DESC, p.sort LIMIT " . (int) $limit . ' OFFSET ' . max(0, $offset));
        $st->execute($w[1]);
        return $st->fetchAll();
    }

    public function countSearch(string $q): int
    {
        if (!($w = $this->searchWhere($q))) return 0;
        $st = $this->db->prepare("SELECT COUNT(*) FROM products p WHERE {$w[0]}");
        $st->execute($w[1]);
        return (int) $st->fetchColumn();
    }

    public function redirectFor(string $path): ?string
    {
        $st = $this->db->prepare('SELECT to_path FROM redirects WHERE from_path = ?');
        $st->execute([$path]);
        return $st->fetchColumn() ?: null;
    }
}
