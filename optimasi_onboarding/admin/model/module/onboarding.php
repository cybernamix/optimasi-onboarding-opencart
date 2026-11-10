<?php
/**
 * Optimasi Content Audit — the product scanner.
 *
 * Reads the store's own catalogue and returns factual measurements only. No
 * scoring weights, thresholds or heuristics live here: the Optimasi service
 * owns those, and this file only counts what a product has.
 *
 * Nothing is written. Every query is a SELECT, and the one table that belongs to
 * a *different* extension (`optimasi_content`, written by the Optimasi Content
 * Manager) is read through a guard, because on a store that has not installed
 * that extension the table does not exist and the scan must still work.
 *
 * The payload keys are the WooCommerce plugin's keys, deliberately: the review
 * endpoint (`PreviewProduct` in the backend) accepts one shape and the two
 * platforms must produce the same one, otherwise "same functionality" drifts
 * into two products that merely look similar.
 */

namespace Opencart\Admin\Model\Extension\OptimasiOnboarding\Module;

class Onboarding extends \Opencart\System\Engine\Model {

    /**
     * Products are measured in chunks so no single statement carries a thousand
     * ids, and so a store with a huge catalogue does not build one enormous
     * result set in memory.
     */
    private const CHUNK = 200;

    /** The seven content blocks the scan measures, in report order. */
    private const FEATURE_KEYS = ['narrative', 'brand_story', 'reviews', 'trust', 'faq', 'specs', 'images'];

    /**
     * Measure up to `$cap` published products.
     *
     * @param int  $language_id  description language to read (the admin's, falling
     *                           back to the store default for any product without
     *                           a row in it)
     * @param int  $cap          maximum products to scan
     * @param bool $with_text    attach description text (only for the voice sample)
     * @param int  $text_sample  how many products carry text, spread evenly; 0 = all
     *
     * @return array{total:int,audited_at:string,completeness:int,features:array,products:array,authority:array}
     */
    public function scan(int $language_id, int $cap, bool $with_text = false, int $text_sample = 0): array {
        $cap = max(1, min(1000, $cap));

        $ids = $this->productIds($cap);

        // The language the store falls back to. A product whose description is
        // missing in the requested language is read in this one rather than
        // reported as having no description at all — the second would be a
        // measurement of our query, not of the store.
        $default_language = (int)$this->config->get('config_language_id');
        $languages       = array_values(array_unique(array_filter([$language_id, $default_language])));

        // When a sample is requested, attach text to an even spread of products
        // (positions floor(k * n / sample)) so the sample is representative of
        // the catalogue's order rather than its first fifty products.
        $text_indices = [];

        if ($with_text && $text_sample > 0 && $ids) {
            $total = count($ids);
            $take  = min($text_sample, $total);

            for ($k = 0; $k < $take; $k++) {
                $text_indices[(int)floor($k * $total / $take)] = true;
            }
        }

        $manufacturers = $this->manufacturerNames();
        $content_ids   = $this->contentProductIds();

        $feature_pass = array_fill_keys(self::FEATURE_KEYS, 0);
        $products     = [];
        $count        = 0;
        $offset       = 0;

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $descriptions = $this->descriptions($chunk, $languages, $language_id, $default_language);
            $images       = $this->imageCounts($chunk);
            $reviews      = $this->reviewStats($chunk);
            $categories   = $this->categoryNames($chunk, $languages, $language_id, $default_language);
            $attributes   = $this->attributeCounts($chunk, $language_id, $default_language);
            $meta         = $this->productMeta($chunk);
            $headings     = $this->headingCounts($chunk, $languages);

            foreach ($chunk as $i => $product_id) {
                if (!isset($meta[$product_id])) {
                    // Vanished between the id query and this one.
                    continue;
                }

                $row = $meta[$product_id];

                $long_html = (string)($descriptions[$product_id]['description'] ?? '');
                $meta_desc = (string)($descriptions[$product_id]['meta_description'] ?? '');
                $name      = self::decodeText((string)($descriptions[$product_id]['name'] ?? ''));

                if ($name === '') {
                    $name = '#' . $product_id;
                }

                $long_text = self::plainText($long_html);
                $short_text = self::plainText($meta_desc);

                $chars_long = self::textLength($long_text);
                $chars_short = self::textLength($short_text);

                $image_count  = (int)($images[$product_id] ?? 0);
                $review_count = (int)($reviews[$product_id]['count'] ?? 0);
                $rating       = (float)($reviews[$product_id]['average'] ?? 0.0);

                $manufacturer_id = (int)$row['manufacturer_id'];
                $has_brand       = $manufacturer_id > 0;
                $brand_terms     = $has_brand && isset($manufacturers[$manufacturer_id])
                    ? [$manufacturers[$manufacturer_id]]
                    : [];

                $cats = array_values(array_map(static fn (array $c): string => $c['name'], $categories[$product_id] ?? []));
                // Marker detection reads the PUBLISHED description, exactly as the
                // WooCommerce plugin does: an unresolved marker still counts,
                // because the merchant has tried to place the block.
                $content = $long_html;

                $features = [
                    'narrative'   => ($chars_long >= 600) && (($headings[$product_id] ?? 0) >= 2),
                    'brand_story' => $has_brand || self::hasMarker($content, '[brand_'),
                    'reviews'     => (self::hasMarker($content, '[review_prod_') || self::hasMarker($content, '[review_brand_'))
                        && $review_count > 0,
                    'trust'       => self::hasMarker($content, '[trust_'),
                    'faq'         => self::hasMarker($content, '[faq_prod_') || self::hasMarker($content, '[faq_brand_'),
                    'specs'       => isset($categories[$product_id]) && count($categories[$product_id]) > 0
                        && (int)($attributes[$product_id] ?? 0) > 0,
                    'images'      => $image_count >= 3,
                ];

                foreach (self::FEATURE_KEYS as $key) {
                    if ($features[$key]) {
                        $feature_pass[$key]++;
                    }
                }

                $product = [
                    'product_id'          => (int)$product_id,
                    'title'               => $name,
                    'sku'                 => (string)$row['sku'],
                    'edit_url'            => $this->editUrl((int)$product_id),
                    'permalink'           => $this->permalink((int)$product_id),
                    'chars_short_desc'    => $chars_short,
                    'chars_long_desc'     => $chars_long,
                    'words_short_desc'    => self::wordCount($short_text),
                    'words_long_desc'     => self::wordCount($long_text),
                    'image_count'         => $image_count,
                    'review_count'        => $review_count,
                    'average_rating'      => round($rating, 2),
                    'has_brand'           => $has_brand,
                    'brand_terms'         => $brand_terms,
                    'categories'          => array_slice($cats, 0, 3),
                    'rewritten'           => isset($content_ids[(int)$product_id]),
                    // OpenCart has no published-version concept, so there is
                    // nothing honest to put here. The WooCommerce plugin fills it
                    // from post meta written by its own publisher.
                    'optimasi_version_id' => '',
                    'features'            => $features,
                ];

                if ($with_text && ($text_sample <= 0 || isset($text_indices[$offset + $i]))) {
                    $product['original_short'] = self::truncate($short_text, 2000);
                    $product['original_long']  = self::truncate($long_text, 4000);
                }

                $products[] = $product;
                $count++;
            }

            $offset += count($chunk);
        }

        $completeness = 0;

        if ($count > 0) {
            $completeness = (int)round(array_sum($feature_pass) / ($count * count(self::FEATURE_KEYS)) * 100);
        }

        $features = [];

        foreach ($feature_pass as $key => $pass) {
            $features[$key] = ['pass' => $pass, 'total' => $count];
        }

        return [
            'total'        => $count,
            'audited_at'   => gmdate('Y-m-d\TH:i:s\Z'),
            'completeness' => $completeness,
            'features'     => $features,
            'products'     => $products,
            'authority'    => $this->authority($products),
        ];
    }

    // ------------------------------------------------------------------
    // Queries
    // ------------------------------------------------------------------

    /**
     * The published product ids to scan, ascending, capped.
     *
     * `status = 1` is OpenCart's "enabled". Disabled products are excluded for
     * the same reason the WooCommerce plugin excludes drafts: the review is
     * about what shoppers can see.
     *
     * @return int[]
     */
    private function productIds(int $cap): array {
        $rows = $this->db->query(
            "SELECT `product_id` FROM `" . DB_PREFIX . "product`
              WHERE `status` = '1'
              ORDER BY `product_id` ASC
              LIMIT " . (int)$cap
        )->rows;

        return array_map(static fn (array $row): int => (int)$row['product_id'], $rows);
    }

    /**
     * Description rows, preferring the requested language per product.
     *
     * One query for both languages rather than two passes: a product is present
     * in the requested language on almost every store, and the fallback only
     * matters for the ones that are not.
     *
     * @param int[] $ids
     * @param int[] $languages
     *
     * @return array<int,array{name:string,description:string,meta_description:string}>
     */
    private function descriptions(array $ids, array $languages, int $preferred, int $default): array {
        $rows = $this->db->query(
            "SELECT `product_id`, `language_id`, `name`, `description`, `meta_description`
               FROM `" . DB_PREFIX . "product_description`
              WHERE `product_id` IN (" . $this->inList($ids) . ")
                AND `language_id` IN (" . $this->inList($languages) . ")"
        )->rows;

        $out = [];

        foreach ($rows as $row) {
            // Keep the preferred language where both exist.
            if (isset($out[(int)$row['product_id']]) && (int)$row['language_id'] !== $preferred) {
                continue;
            }

            $out[(int)$row['product_id']] = [
                'name'             => (string)$row['name'],
                'description'      => (string)$row['description'],
                'meta_description' => (string)$row['meta_description'],
            ];
        }

        return $out;
    }

    /**
     * Distinct non-empty image paths: the primary image plus the gallery.
     *
     * Duplicates are collapsed because two rows naming the same file are one
     * usable image, and overstating the count would overstate the `images`
     * block. The WooCommerce plugin's featured-plus-gallery count can be read
     * the same way.
     *
     * @param int[] $ids
     *
     * @return array<int,int>
     */
    private function imageCounts(array $ids): array {
        $out = [];

        $primary = $this->db->query(
            "SELECT `product_id`, `image` FROM `" . DB_PREFIX . "product`
              WHERE `product_id` IN (" . $this->inList($ids) . ")"
        )->rows;

        foreach ($primary as $row) {
            $image = trim((string)$row['image']);

            if ($image !== '') {
                $out[(int)$row['product_id']][$image] = true;
            }
        }

        $gallery = $this->db->query(
            "SELECT `product_id`, `image` FROM `" . DB_PREFIX . "product_image`
              WHERE `product_id` IN (" . $this->inList($ids) . ")
              ORDER BY `sort_order` ASC, `product_image_id` ASC"
        )->rows;

        foreach ($gallery as $row) {
            $image = trim((string)$row['image']);

            if ($image !== '') {
                $out[(int)$row['product_id']][$image] = true;
            }
        }

        return array_map(static fn (array $set): int => count($set), $out);
    }

    /**
     * Approved review count and average rating per product.
     *
     * `status = 1` is "approved"; an unapproved review is not on the storefront,
     * so counting it would report content the shopper cannot see.
     *
     * @param int[] $ids
     *
     * @return array<int,array{count:int,average:float}>
     */
    private function reviewStats(array $ids): array {
        $rows = $this->db->query(
            "SELECT `product_id`, COUNT(*) AS `total`, AVG(`rating`) AS `average`
               FROM `" . DB_PREFIX . "review`
              WHERE `status` = '1'
                AND `product_id` IN (" . $this->inList($ids) . ")
              GROUP BY `product_id`"
        )->rows;

        $out = [];

        foreach ($rows as $row) {
            $out[(int)$row['product_id']] = [
                'count'   => (int)$row['total'],
                'average' => (float)$row['average'],
            ];
        }

        return $out;
    }

    /**
     * Category names per product, primary first.
     *
     * OpenCart has no "primary category" — `product_to_category` is an unordered
     * set — so the lowest category id is treated as primary. That is deterministic
     * and stable across runs, which is what the payload needs; it is not a claim
     * about which category the merchant considers primary.
     *
     * @param int[] $ids
     * @param int[] $languages
     *
     * @return array<int,array<int,array{id:int,name:string}>>
     */
    private function categoryNames(array $ids, array $languages, int $preferred, int $default): array {
        $links = $this->db->query(
            "SELECT `product_id`, `category_id`
               FROM `" . DB_PREFIX . "product_to_category`
              WHERE `product_id` IN (" . $this->inList($ids) . ")
              ORDER BY `product_id` ASC, `category_id` ASC"
        )->rows;

        if (!$links) {
            return [];
        }

        $category_ids = array_values(array_unique(array_map(static fn (array $r): int => (int)$r['category_id'], $links)));

        $names = [];

        $rows = $this->db->query(
            "SELECT `category_id`, `language_id`, `name`
               FROM `" . DB_PREFIX . "category_description`
              WHERE `category_id` IN (" . $this->inList($category_ids) . ")
                AND `language_id` IN (" . $this->inList($languages) . ")"
        )->rows;

        foreach ($rows as $row) {
            if (isset($names[(int)$row['category_id']]) && (int)$row['language_id'] !== $preferred) {
                continue;
            }

            $names[(int)$row['category_id']] = self::decodeText((string)$row['name']);
        }

        $out = [];

        foreach ($links as $row) {
            $category_id = (int)$row['category_id'];
            $name        = self::decodeText((string)($names[$category_id] ?? ''));

            if ($name === '') {
                continue;
            }

            $out[(int)$row['product_id']][] = ['id' => $category_id, 'name' => $name];
        }

        return $out;
    }

    /**
     * How many attribute rows each product has, in the scanned language.
     *
     * Presence is all the `specs` rule needs, so this counts rather than fetches.
     *
     * @param int[] $ids
     *
     * @return array<int,int>
     */
    private function attributeCounts(array $ids, int $preferred, int $default): array {
        $rows = $this->db->query(
            "SELECT `product_id`, COUNT(*) AS `total`
               FROM `" . DB_PREFIX . "product_attribute`
              WHERE `product_id` IN (" . $this->inList($ids) . ")
                AND `language_id` = '" . (int)$preferred . "'
              GROUP BY `product_id`"
        )->rows;

        $out = [];

        foreach ($rows as $row) {
            $out[(int)$row['product_id']] = (int)$row['total'];
        }

        // Fall back to the store default for products with nothing in the
        // requested language, for the same reason descriptions fall back.
        if ($default !== $preferred) {
            $missing = array_values(array_filter($ids, static fn (int $id): bool => !isset($out[$id])));

            if ($missing) {
                $rows = $this->db->query(
                    "SELECT `product_id`, COUNT(*) AS `total`
                       FROM `" . DB_PREFIX . "product_attribute`
                      WHERE `product_id` IN (" . $this->inList($missing) . ")
                        AND `language_id` = '" . (int)$default . "'
                      GROUP BY `product_id`"
                )->rows;

                foreach ($rows as $row) {
                    $out[(int)$row['product_id']] = (int)$row['total'];
                }
            }
        }

        return $out;
    }

    /**
     * Row facts the scan needs from `product` itself.
     *
     * @param int[] $ids
     *
     * @return array<int,array{sku:string,manufacturer_id:int}>
     */
    private function productMeta(array $ids): array {
        $rows = $this->db->query(
            "SELECT `product_id`, `sku`, `model`, `manufacturer_id`
               FROM `" . DB_PREFIX . "product`
              WHERE `product_id` IN (" . $this->inList($ids) . ")"
        )->rows;

        $out = [];

        foreach ($rows as $row) {
            // `sku` is the field the payload calls sku; `model` is the fallback
            // for the many stores that only ever fill in the model number.
            $sku = trim((string)$row['sku']);

            if ($sku === '') {
                $sku = trim((string)$row['model']);
            }

            $out[(int)$row['product_id']] = [
                'sku'             => $sku,
                'manufacturer_id' => (int)$row['manufacturer_id'],
            ];
        }

        return $out;
    }

    /**
     * Count of `<h2>`/`<h3>` openings per product.
     *
     * Read from the raw HTML, because that is what the rule is about — the
     * headings are stripped with the rest of the tags by `plainText()`.
     *
     * @param int[] $ids
     * @param int[] $languages
     *
     * @return array<int,int>
     */
    private function headingCounts(array $ids, array $languages): array {
        $rows = $this->db->query(
            "SELECT `product_id`, `description`
               FROM `" . DB_PREFIX . "product_description`
              WHERE `product_id` IN (" . $this->inList($ids) . ")
                AND `language_id` IN (" . $this->inList($languages) . ")"
        )->rows;

        $out = [];

        foreach ($rows as $row) {
            $found = preg_match_all('/<h[23][^>]*>/i', (string)$row['description']);

            // Prefer the highest count seen, which is the requested language
            // when it exists (the fallback copy normally has fewer or the same).
            if ($found > ($out[(int)$row['product_id']] ?? 0)) {
                $out[(int)$row['product_id']] = (int)$found;
            }
        }

        return $out;
    }

    /**
     * Manufacturer names, one query for the whole table (it is small and is
     * almost always read in full).
     *
     * @return array<int,string>
     */
    private function manufacturerNames(): array {
        $rows = $this->db->query(
            "SELECT `manufacturer_id`, `name` FROM `" . DB_PREFIX . "manufacturer`"
        )->rows;

        $out = [];

        foreach ($rows as $row) {
            $out[(int)$row['manufacturer_id']] = self::decodeText((string)$row['name']);
        }

        return $out;
    }

    /**
     * A display name as plain text, with HTML entities resolved.
     *
     * OpenCart stores whatever the merchant typed or the import delivered, so a title
     * can legitimately hold `&amp;` and reach the report as literal `Black &amp; Red
     * Ribbon`. Decoded here, once, so every consumer — the evidence table, the
     * worst-products list, the findings payload and the PDF — shows the same text.
     *
     * Descriptions are deliberately NOT decoded: they are HTML, their markers are
     * matched against the stored form, and their own plain-text view already decodes.
     */
    private static function decodeText(string $value): string {
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Product ids that already have an Optimasi content block.
     *
     * The table belongs to the Optimasi Content Manager extension, so it may not
     * exist. Absence means "no blocks yet", which is exactly what the scan
     * should report on a store that has only installed this extension — it is
     * not an error, and it must not be logged as one.
     *
     * The tags are read once for the whole catalogue rather than joined per
     * product: the table holds a handful of rows per optimised product, and one
     * pass avoids building a `LIKE '%\_prod\_123%'` per id (the underscores in
     * those patterns are LIKE wildcards, which is the trap that makes this
     * approach worth the extra function).
     *
     * @return array<int,bool>
     */
    private function contentProductIds(): array {
        $sql = "SELECT `shortcode_tag` FROM `" . DB_PREFIX . "optimasi_content`";

        try {
            $rows = $this->db->query($sql)->rows;
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            $tag = (string)$row['shortcode_tag'];

            // `faq_prod_123`, `review_prod_123`.
            if (preg_match('/_prod_(\d+)$/', $tag, $m)) {
                $out[(int)$m[1]] = true;
            }

            // `tab_123_FAQ`, `tab_123_Specs` — the container rows the product
            // page renders (docs/70 §6).
            if (preg_match('/^tab_(\d+)_/', $tag, $m)) {
                $out[(int)$m[1]] = true;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Aggregates
    // ------------------------------------------------------------------

    /**
     * The `authority` block.
     *
     * OpenCart has no blog content type, so there is nothing to measure and the
     * counts are honestly zero rather than absent. `platform` is what lets the
     * server soften the copy instead of telling an OpenCart merchant they
     * ignored their blog (docs/84 §8, D7 — the server-side half of that is still
     * open, recorded as B4).
     *
     * @param array $products Scanned rows, each carrying 'categories'.
     *
     * @return array
     */
    private function authority(array $products): array {
        $tally = [];

        foreach ($products as $product) {
            foreach ((array)$product['categories'] as $name) {
                $name = trim((string)$name);

                if ($name !== '') {
                    $tally[$name] = ($tally[$name] ?? 0) + 1;
                }
            }
        }

        arsort($tally);

        $top = [];

        foreach (array_slice($tally, 0, 12, true) as $name => $count) {
            $top[] = ['name' => (string)$name, 'products' => (int)$count];
        }

        return [
            'platform'         => 'opencart4',
            'blog_posts'       => 0,
            'recent_posts_90d' => 0,
            'in_depth_posts'   => 0,
            'latest_post_days' => null,
            'catalog'          => [
                'total_products' => count($products),
                'categories'     => $top,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

    /** A comma-separated list of integers, for `IN (...)` clauses. */
    private function inList(array $values): string {
        $values = array_map('intval', $values);

        return $values ? implode(',', $values) : '0';
    }

    /** Does the description carry this shortcode marker? */
    private static function hasMarker(string $content, string $marker): bool {
        return $content !== '' && stripos($content, $marker) !== false;
    }

    /**
     * Tag-stripped, entity-decoded text.
     *
     * Entities are decoded before the length is taken, unlike WordPress's
     * `wp_strip_all_tags`: CKEditor writes `&nbsp;` for every space a merchant
     * types, and counting those six characters each would overstate description
     * length on exactly the stores this review is meant to help.
     */
    private static function plainText(string $html): string {
        if ($html === '') {
            return '';
        }

        // Script and style bodies are not text the shopper reads.
        $html = (string)preg_replace('@<(script|style)[^>]*?>.*?</\1>@si', '', $html);
        $html = strip_tags($html);
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($html);
    }

    private static function textLength(string $text): int {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    private static function wordCount(string $text): int {
        $text = (string)preg_replace('/\s+/u', ' ', trim($text));

        if ($text === '') {
            return 0;
        }

        $tokens = preg_split('/[\p{Z}\p{Cc}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($tokens) ? count($tokens) : 0;
    }

    private static function truncate(string $text, int $max): string {
        $text = (string)preg_replace('/\s+/u', ' ', trim($text));

        return function_exists('mb_substr') ? mb_substr($text, 0, $max, 'UTF-8') : substr($text, 0, $max);
    }

    /**
     * The admin link that opens the product, so a report row is one click from
     * the thing it describes.
     */
    private function editUrl(int $product_id): string {
        if (empty($this->session->data['user_token'])) {
            return '';
        }

        return $this->url->link(
            'catalog/product.form',
            'user_token=' . $this->session->data['user_token'] . '&product_id=' . $product_id
        );
    }

    /**
     * The storefront URL for the product.
     *
     * The plain `index.php?route=` form rather than the SEO URL: resolving the
     * keyword means reading `seo_url`, and a wrong link in a report is worse
     * than an ugly one. It always resolves.
     */
    private function permalink(int $product_id): string {
        $base = defined('HTTP_CATALOG') ? HTTP_CATALOG : '';
        $base = rtrim($base, '/') . '/';

        return $base . 'index.php?route=product/product&product_id=' . $product_id;
    }
}
