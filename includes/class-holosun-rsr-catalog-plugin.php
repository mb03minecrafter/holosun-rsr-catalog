<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Holosun_RSR_Catalog_Plugin
{
    const VERSION = '2.0.0';

    const OPTION_SETTINGS = 'hrc_settings';
    const OPTION_CATALOG_CACHE = 'hrc_phoenix_catalog_cache_v2';
    const OPTION_LAST_SYNC = 'hrc_last_sync';
    const OPTION_LAST_SYNC_STATUS = 'hrc_last_sync_status';
    const OPTION_LAST_SYNC_MESSAGE = 'hrc_last_sync_message';
    const OPTION_LAST_SYNC_COUNT = 'hrc_last_sync_count';

    const ADMIN_SLUG = 'holosun-rsr-catalog';
    const CRON_HOOK = 'hrc_sync_event';
    const CRON_SCHEDULE = 'hrc_every_five_minutes';
    const SHORTCODE = 'holosun_rsr_list';

    private static $booted = false;
    private static $assets_printed = false;

    public static function boot()
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        register_activation_hook(HRC_PLUGIN_FILE, array(__CLASS__, 'activate'));
        register_deactivation_hook(HRC_PLUGIN_FILE, array(__CLASS__, 'deactivate'));

        add_filter('cron_schedules', array(__CLASS__, 'register_cron_schedule'));
        add_action('init', array(__CLASS__, 'ensure_schedule'), 5);
        add_action('admin_menu', array(__CLASS__, 'register_admin_menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('admin_post_hrc_sync_now', array(__CLASS__, 'handle_sync_now'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'run_scheduled_sync'));

        add_shortcode(self::SHORTCODE, array(__CLASS__, 'render_products_shortcode'));
        add_filter('the_content', array(__CLASS__, 'append_front_page_list'), 20);
    }

    public static function activate()
    {
        self::schedule_refresh();
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function register_cron_schedule($schedules)
    {
        $schedules[self::CRON_SCHEDULE] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => 'Every five minutes',
        );

        return $schedules;
    }

    public static function ensure_schedule()
    {
        $event = function_exists('wp_get_scheduled_event')
            ? wp_get_scheduled_event(self::CRON_HOOK)
            : null;

        if ($event && isset($event->schedule) && $event->schedule === self::CRON_SCHEDULE) {
            return;
        }

        wp_clear_scheduled_hook(self::CRON_HOOK);
        self::schedule_refresh();
    }

    private static function schedule_refresh()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    public static function register_admin_menu()
    {
        add_options_page(
            'Holosun Phoenix Catalog',
            'Holosun Phoenix Catalog',
            'manage_options',
            self::ADMIN_SLUG,
            array(__CLASS__, 'render_settings_page')
        );
    }

    public static function register_settings()
    {
        register_setting(
            'hrc_settings_group',
            self::OPTION_SETTINGS,
            array(__CLASS__, 'sanitize_settings')
        );
    }

    public static function sanitize_settings($input)
    {
        $input = is_array($input) ? $input : array();

        $api_base_url = isset($input['phoenix_api_base_url'])
            ? trim((string) wp_unslash($input['phoenix_api_base_url']))
            : 'http://127.0.0.1:8080';
        $dealer_urls_input = isset($input['dealer_urls'])
            ? (string) wp_unslash($input['dealer_urls'])
            : '';

        $api_base_url = esc_url_raw($api_base_url, array('http', 'https'));
        if ($api_base_url === '') {
            $api_base_url = 'http://127.0.0.1:8080';
        }

        delete_option(self::OPTION_CATALOG_CACHE);

        return array(
            'phoenix_api_base_url' => untrailingslashit($api_base_url),
            'dealer_urls' => self::sanitize_dealer_urls_option($dealer_urls_input),
        );
    }

    public static function get_settings()
    {
        $defaults = array(
            'phoenix_api_base_url' => 'http://127.0.0.1:8080',
            'dealer_urls' => '',
        );

        $saved = get_option(self::OPTION_SETTINGS, array());
        if (!is_array($saved)) {
            $saved = array();
        }

        return wp_parse_args($saved, $defaults);
    }

    public static function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = self::get_settings();
        $notice = self::pull_admin_notice();
        $last_sync = get_option(self::OPTION_LAST_SYNC, '');
        $last_status = get_option(self::OPTION_LAST_SYNC_STATUS, '');
        $last_message = get_option(self::OPTION_LAST_SYNC_MESSAGE, '');
        $last_count = (int) get_option(self::OPTION_LAST_SYNC_COUNT, 0);
        ?>
        <div class="wrap">
            <h1>Holosun Phoenix Catalog</h1>
            <p>The public catalog is built from current Phoenix product and pricing data. The cached catalog remains available if Phoenix is temporarily unreachable.</p>
            <p>Shortcode: <code>[<?php echo esc_html(self::SHORTCODE); ?>]</code></p>

            <?php if (!empty($notice)) : ?>
                <div class="notice <?php echo !empty($notice['success']) ? 'notice-success' : 'notice-error'; ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($last_sync !== '') : ?>
                <div class="notice notice-info">
                    <p>
                        Last refresh: <strong><?php echo esc_html($last_sync); ?></strong><br>
                        Status: <strong><?php echo esc_html($last_status !== '' ? $last_status : 'n/a'); ?></strong><br>
                        Published cards: <strong><?php echo esc_html((string) $last_count); ?></strong><br>
                        Message: <?php echo esc_html($last_message !== '' ? $last_message : 'n/a'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('hrc_settings_group'); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                    <tr>
                        <th scope="row"><label for="hrc_phoenix_api_base_url">Phoenix API Base URL</label></th>
                        <td>
                            <input
                                name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[phoenix_api_base_url]"
                                id="hrc_phoenix_api_base_url"
                                type="url"
                                class="regular-text code"
                                value="<?php echo esc_attr($settings['phoenix_api_base_url']); ?>"
                            >
                            <p class="description">Use the private same-server address in production: <code>http://127.0.0.1:8080</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hrc_dealer_urls">Partner Dealer Search URLs</label></th>
                        <td>
                            <textarea name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[dealer_urls]" id="hrc_dealer_urls" rows="8" class="large-text code" placeholder="https://example.com/search?q={sku}"><?php echo esc_textarea($settings['dealer_urls']); ?></textarea>
                            <p class="description">One URL template per line. Use <code>{sku}</code> where the Holosun manufacturer part number belongs. Normal clicks route to Deerford Defense 80% of the time and a randomly selected partner 20% of the time.</p>
                        </td>
                    </tr>
                    </tbody>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>

            <hr>

            <h2>Catalog Refresh</h2>
            <p>Phoenix refreshes automatically every five minutes. A manual refresh replaces the cache only after the complete catalog validates successfully.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="hrc_sync_now">
                <?php wp_nonce_field('hrc_sync_now'); ?>
                <?php submit_button('Refresh Now', 'primary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    public static function handle_sync_now()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'holosun-rsr-catalog'));
        }

        check_admin_referer('hrc_sync_now');

        $result = self::refresh_catalog();
        self::push_admin_notice(
            !empty($result['success']),
            isset($result['message']) ? (string) $result['message'] : 'Catalog refresh finished.'
        );

        wp_safe_redirect(admin_url('options-general.php?page=' . self::ADMIN_SLUG));
        exit;
    }

    public static function run_scheduled_sync()
    {
        self::refresh_catalog();
    }

    public static function refresh_catalog()
    {
        $result = self::fetch_phoenix_catalog();
        $existing = self::read_catalog_cache();

        if (empty($result['success'])) {
            $existing_count = isset($existing['products']) && is_array($existing['products'])
                ? count($existing['products'])
                : 0;

            return self::finalize_refresh(
                false,
                $existing_count,
                isset($result['message']) ? (string) $result['message'] : 'Phoenix catalog refresh failed.'
            );
        }

        $products = isset($result['products']) && is_array($result['products'])
            ? $result['products']
            : array();

        if (empty($products)) {
            return self::finalize_refresh(false, 0, 'Phoenix returned no eligible Holosun products; the prior cache was preserved.');
        }

        $cache = array(
            'version' => self::VERSION,
            'updated_at' => time(),
            'products' => $products,
        );

        update_option(self::OPTION_CATALOG_CACHE, $cache, false);

        return self::finalize_refresh(
            true,
            count($products),
            sprintf('Loaded %d published, in-stock Holosun products from Phoenix.', count($products))
        );
    }

    private static function finalize_refresh($success, $count, $message)
    {
        update_option(self::OPTION_LAST_SYNC, current_time('mysql'));
        update_option(self::OPTION_LAST_SYNC_STATUS, $success ? 'success' : 'error');
        update_option(self::OPTION_LAST_SYNC_MESSAGE, (string) $message);
        update_option(self::OPTION_LAST_SYNC_COUNT, (int) $count);

        return array(
            'success' => (bool) $success,
            'count' => (int) $count,
            'message' => (string) $message,
        );
    }

    private static function fetch_phoenix_catalog()
    {
        $product_result = self::fetch_all_pages(
            '/api/v1/product-management/products',
            array('search' => 'Holosun')
        );
        if (empty($product_result['success'])) {
            return $product_result;
        }

        $price_result = self::fetch_all_pages(
            '/api/v1/gunbroker/bulk-listings/candidates',
            array(
                'environment' => 'production',
                'search' => 'Holosun',
                'eligibleOnly' => 'false',
            )
        );
        if (empty($price_result['success'])) {
            return $price_result;
        }

        $prices_by_upc = array();
        foreach ($price_result['items'] as $price_row) {
            if (!is_array($price_row)) {
                continue;
            }

            $upc = isset($price_row['upc']) ? trim((string) $price_row['upc']) : '';
            $price = isset($price_row['finalSalePrice']) ? (float) $price_row['finalSalePrice'] : 0.0;
            if ($upc !== '' && $price > 0) {
                $prices_by_upc[$upc] = $price;
            }
        }

        $products = array();
        foreach ($product_result['items'] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $brand = isset($row['brand']) ? trim((string) $row['brand']) : '';
            if (strcasecmp($brand, 'Holosun') !== 0) {
                continue;
            }

            $status = isset($row['wooProductStatus']) ? strtolower(trim((string) $row['wooProductStatus'])) : '';
            $quantity = isset($row['selectedQuantityAvailable']) ? (int) $row['selectedQuantityAvailable'] : 0;
            $woo_product_id = isset($row['wooProductId']) ? (int) $row['wooProductId'] : 0;
            $upc = isset($row['upc']) ? trim((string) $row['upc']) : '';
            $title = isset($row['productName']) ? self::clean_text($row['productName']) : '';
            $permalink = isset($row['wooProductPermalink']) ? esc_url_raw((string) $row['wooProductPermalink']) : '';
            $image_url = isset($row['firstImageUrl']) ? esc_url_raw((string) $row['firstImageUrl']) : '';
            $part_number = isset($row['manufacturerPartNumber'])
                ? self::clean_text($row['manufacturerPartNumber'])
                : '';

            if (
                $status !== 'publish'
                || $quantity <= 0
                || $woo_product_id <= 0
                || $upc === ''
                || $title === ''
                || $permalink === ''
                || $image_url === ''
            ) {
                continue;
            }

            $real_price = isset($prices_by_upc[$upc]) ? (float) $prices_by_upc[$upc] : 0.0;
            if ($real_price <= 0) {
                $real_price = self::fetch_single_real_price($upc);
            }
            if ($real_price <= 0) {
                continue;
            }

            $uplift_basis_points = self::stable_uplift_basis_points($upc);
            $display_price = round($real_price * (1 + ($uplift_basis_points / 10000)), 2);

            $products[] = array(
                'upc' => $upc,
                'title' => $title,
                'part_number' => $part_number,
                'permalink' => $permalink,
                'image_url' => $image_url,
                'display_price' => $display_price,
            );
        }

        usort($products, function ($left, $right) {
            return strcasecmp((string) $left['title'], (string) $right['title']);
        });

        return array(
            'success' => true,
            'products' => $products,
        );
    }

    private static function fetch_all_pages($path, $query)
    {
        $all_items = array();
        $page = 1;
        $page_size = 250;
        $max_pages = 25;

        while ($page <= $max_pages) {
            $page_query = array_merge(
                $query,
                array(
                    'page' => $page,
                    'pageSize' => $page_size,
                )
            );
            $response = self::api_get($path, $page_query);
            if (empty($response['success'])) {
                return $response;
            }

            $body = isset($response['body']) && is_array($response['body'])
                ? $response['body']
                : array();
            $items = isset($body['items']) && is_array($body['items'])
                ? $body['items']
                : null;

            if ($items === null) {
                return array(
                    'success' => false,
                    'message' => 'Phoenix returned an invalid paginated response for ' . $path . '.',
                );
            }

            $all_items = array_merge($all_items, $items);
            $total_pages = isset($body['totalPages']) ? (int) $body['totalPages'] : 0;
            $total_items = isset($body['total'])
                ? (int) $body['total']
                : (isset($body['totalCount']) ? (int) $body['totalCount'] : 0);

            if ($total_pages > 0 && $page >= $total_pages) {
                break;
            }
            if ($total_items > 0 && count($all_items) >= $total_items) {
                break;
            }
            if (count($items) < $page_size) {
                break;
            }

            $page++;
        }

        if ($page > $max_pages) {
            return array(
                'success' => false,
                'message' => 'Phoenix pagination exceeded the catalog safety limit for ' . $path . '.',
            );
        }

        return array(
            'success' => true,
            'items' => $all_items,
        );
    }

    private static function fetch_single_real_price($upc)
    {
        $path = '/api/v1/gunbroker-pricing/products/' . rawurlencode((string) $upc);
        $response = self::api_get($path, array('environment' => 'production'));
        if (empty($response['success']) || empty($response['body']['wooCommerce'])) {
            return 0.0;
        }

        $woo = $response['body']['wooCommerce'];
        return isset($woo['itemPrice']) ? (float) $woo['itemPrice'] : 0.0;
    }

    private static function api_get($path, $query = array())
    {
        $settings = self::get_settings();
        $base_url = isset($settings['phoenix_api_base_url'])
            ? untrailingslashit((string) $settings['phoenix_api_base_url'])
            : 'http://127.0.0.1:8080';
        $url = $base_url . '/' . ltrim((string) $path, '/');

        if (!empty($query)) {
            $url = add_query_arg($query, $url);
        }

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => 45,
                'redirection' => 0,
                'headers' => array(
                    'Accept' => 'application/json',
                    'User-Agent' => 'HolosunDealsCatalog/' . self::VERSION,
                ),
            )
        );

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Phoenix request failed: ' . $response->get_error_message(),
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return array(
                'success' => false,
                'message' => sprintf('Phoenix request failed with HTTP %d for %s.', $status, $path),
            );
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($decoded)) {
            return array(
                'success' => false,
                'message' => 'Phoenix returned invalid JSON for ' . $path . '.',
            );
        }

        return array(
            'success' => true,
            'body' => $decoded,
        );
    }

    private static function stable_uplift_basis_points($upc)
    {
        $hash = hash('sha256', 'holosundeals|' . (string) $upc);
        $seed = hexdec(substr($hash, 0, 7));

        return 100 + ($seed % 201);
    }

    private static function clean_text($value)
    {
        $value = wp_strip_all_tags((string) $value, true);
        $value = preg_replace('/\s+/', ' ', $value);

        return is_string($value) ? trim($value) : '';
    }

    private static function read_catalog_cache()
    {
        $cache = get_option(self::OPTION_CATALOG_CACHE, array());
        if (!is_array($cache)) {
            return array();
        }

        return $cache;
    }

    private static function get_catalog_for_render()
    {
        $cache = self::read_catalog_cache();
        $products = isset($cache['products']) && is_array($cache['products'])
            ? $cache['products']
            : array();

        if (!empty($products)) {
            return $products;
        }

        $result = self::refresh_catalog();
        if (empty($result['success'])) {
            return array();
        }

        $cache = self::read_catalog_cache();
        return isset($cache['products']) && is_array($cache['products'])
            ? $cache['products']
            : array();
    }

    public static function append_front_page_list($content)
    {
        if (is_admin() || !is_main_query() || !in_the_loop() || !is_front_page()) {
            return $content;
        }

        if (has_shortcode((string) $content, self::SHORTCODE)) {
            return $content;
        }

        return (string) $content . "\n\n" . self::render_products_shortcode(array());
    }

    public static function render_products_shortcode($atts = array())
    {
        unset($atts);

        $settings = self::get_settings();
        $dealer_urls = self::get_dealer_urls_from_option(
            isset($settings['dealer_urls']) ? $settings['dealer_urls'] : ''
        );
        $products = self::get_catalog_for_render();
        $container_id = 'hrc-catalog-' . wp_rand(1000, 999999);
        $grid_id = $container_id . '-grid';
        $search_id = $container_id . '-search';

        ob_start();
        self::print_frontend_assets();
        ?>
        <section class="hrc-catalog" id="<?php echo esc_attr($container_id); ?>" aria-labelledby="<?php echo esc_attr($container_id); ?>-title">
            <header class="hrc-catalog-header">
                <div>
                    <p class="hrc-eyebrow">Updated Holosun pricing</p>
                    <h2 id="<?php echo esc_attr($container_id); ?>-title">Shop Holosun Deals</h2>
                </div>
                <?php if (!empty($products)) : ?>
                    <span class="hrc-product-count" aria-live="polite"><?php echo esc_html(number_format_i18n(count($products))); ?> products</span>
                <?php endif; ?>
            </header>

            <?php if (!empty($products)) : ?>
                <div class="hrc-search-wrap">
                    <label class="screen-reader-text" for="<?php echo esc_attr($search_id); ?>">Search Holosun products</label>
                    <input
                        class="hrc-search"
                        id="<?php echo esc_attr($search_id); ?>"
                        type="search"
                        placeholder="Search by product, model, or UPC"
                        autocomplete="off"
                        aria-controls="<?php echo esc_attr($grid_id); ?>"
                    >
                </div>
                <div class="hrc-product-grid" id="<?php echo esc_attr($grid_id); ?>">
                    <?php foreach ($products as $product) : ?>
                        <?php
                        $title = isset($product['title']) ? (string) $product['title'] : '';
                        $part_number = isset($product['part_number']) ? (string) $product['part_number'] : '';
                        $upc = isset($product['upc']) ? (string) $product['upc'] : '';
                        $sku = $part_number !== '' ? $part_number : $upc;
                        $permalink = isset($product['permalink']) ? (string) $product['permalink'] : '';
                        $image_url = isset($product['image_url']) ? (string) $product['image_url'] : '';
                        $display_price = isset($product['display_price']) ? (float) $product['display_price'] : 0.0;
                        ?>
                        <article
                            class="hrc-product-card"
                            data-search="<?php echo esc_attr(trim($title . ' ' . $part_number . ' ' . $upc)); ?>"
                        >
                            <a
                                class="hrc-product-link"
                                href="<?php echo esc_url($permalink); ?>"
                                data-deerford-url="<?php echo esc_attr($permalink); ?>"
                                data-sku="<?php echo esc_attr($sku); ?>"
                                aria-label="<?php echo esc_attr(sprintf('View deal for %s', $title)); ?>"
                            >
                                <span class="hrc-image-frame">
                                    <img
                                        src="<?php echo esc_url($image_url); ?>"
                                        alt="<?php echo esc_attr($title); ?>"
                                        loading="lazy"
                                        decoding="async"
                                    >
                                </span>
                                <span class="hrc-card-body">
                                    <span class="hrc-product-title"><?php echo esc_html($title); ?></span>
                                    <?php if ($part_number !== '') : ?>
                                        <span class="hrc-product-model"><?php echo esc_html($part_number); ?></span>
                                    <?php endif; ?>
                                    <span class="hrc-card-footer">
                                        <span class="hrc-price-wrap">
                                            <span class="hrc-price-label">Price</span>
                                            <span class="hrc-price">$<?php echo esc_html(number_format($display_price, 2)); ?></span>
                                        </span>
                                        <span class="hrc-view-deal">View Deal <span aria-hidden="true">&rarr;</span></span>
                                    </span>
                                </span>
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="hrc-no-results" hidden>No Holosun products match that search.</div>
            <?php else : ?>
                <div class="hrc-empty-state">
                    Current Holosun deals are temporarily unavailable. Please check back shortly.
                </div>
            <?php endif; ?>
        </section>
        <script>
            (function () {
                var root = document.getElementById(<?php echo wp_json_encode($container_id); ?>);
                if (!root) {
                    return;
                }

                var dealerUrls = <?php echo wp_json_encode(array_values($dealer_urls)); ?>;
                var links = root.querySelectorAll('.hrc-product-link');
                var cards = root.querySelectorAll('.hrc-product-card');
                var search = root.querySelector('.hrc-search');
                var count = root.querySelector('.hrc-product-count');
                var noResults = root.querySelector('.hrc-no-results');

                if (search) {
                    search.addEventListener('input', function () {
                        var query = search.value.trim().toLowerCase();
                        var visibleCount = 0;

                        Array.prototype.forEach.call(cards, function (card) {
                            var searchableText = (card.getAttribute('data-search') || '').toLowerCase();
                            var matches = query === '' || searchableText.indexOf(query) !== -1;
                            card.hidden = !matches;
                            if (matches) {
                                visibleCount += 1;
                            }
                        });

                        if (count) {
                            count.textContent = visibleCount + (visibleCount === 1 ? ' product' : ' products');
                        }
                        if (noResults) {
                            noResults.hidden = visibleCount !== 0;
                        }
                    });
                }

                var normalizeSku = function (sku) {
                    if (typeof sku !== 'string') {
                        return '';
                    }
                    return sku.replace(/^H[\s\-_]*S[\s\-_]*/i, '').trim();
                };

                var buildDealerUrl = function (template, sku) {
                    if (typeof template !== 'string' || !template || !sku) {
                        return '';
                    }

                    var encodedSku = encodeURIComponent(normalizeSku(sku));
                    if (!encodedSku) {
                        return '';
                    }

                    if (/\{sku\}/i.test(template)) {
                        return template.replace(/\{sku\}/ig, encodedSku);
                    }

                    if (/HS507COMP/i.test(template)) {
                        return template.replace(/HS507COMP/ig, encodedSku);
                    }

                    try {
                        var parsed = new URL(template, window.location.origin);
                        var host = parsed.hostname.replace(/^www\./i, '').toLowerCase();
                        var base = parsed.protocol + '//' + parsed.host;

                        if (host === 'checkmatedefense.us') {
                            return base + '/search.php?search_query=' + encodedSku + '&section=product';
                        }
                        if (host === 'fiddlersdefense.com') {
                            return base + '/search/' + encodedSku;
                        }
                        if (host === 'graniteridgedefense.com') {
                            return base + '/search?q=' + encodedSku + '&options%5Bprefix%5D=last';
                        }
                        if (host === 'deleonrxgunsandammo.com') {
                            return base + '/product-search-results?search_text=' + encodedSku + '&product_department_id=603&from_form=true';
                        }
                        if (host === 'highmountainarmory.com') {
                            return base + '/product-department/optics?q=' + encodedSku;
                        }
                        if (host === 'daycoammo.com') {
                            return base + '/search?q=' + encodedSku;
                        }
                    } catch (error) {
                        return '';
                    }

                    return '';
                };

                Array.prototype.forEach.call(links, function (link) {
                    link.addEventListener('click', function (event) {
                        if (
                            event.defaultPrevented
                            || event.button !== 0
                            || event.metaKey
                            || event.ctrlKey
                            || event.shiftKey
                            || event.altKey
                        ) {
                            return;
                        }

                        if (!Array.isArray(dealerUrls) || dealerUrls.length === 0 || Math.random() >= 0.20) {
                            return;
                        }

                        var sku = normalizeSku(link.getAttribute('data-sku') || '');
                        var dealerTemplate = dealerUrls[Math.floor(Math.random() * dealerUrls.length)];
                        var dealerUrl = buildDealerUrl(dealerTemplate, sku);
                        if (!dealerUrl) {
                            return;
                        }

                        event.preventDefault();
                        window.location.assign(dealerUrl);
                    });
                });
            })();
        </script>
        <?php

        return (string) ob_get_clean();
    }

    private static function print_frontend_assets()
    {
        if (self::$assets_printed) {
            return;
        }

        self::$assets_printed = true;
        ?>
        <style>
            .hrc-catalog {
                width: min(1440px, calc(100% - 32px));
                margin: 28px auto 48px;
                color: var(--theme-text-color, #e7edf3);
            }

            .hrc-catalog-header {
                display: flex;
                align-items: flex-end;
                justify-content: space-between;
                gap: 24px;
                margin-bottom: 18px;
                padding-bottom: 14px;
                border-bottom: 3px solid var(--theme-link-initial-color, #59c3ff);
            }

            .hrc-eyebrow {
                margin: 0 0 4px;
                color: var(--theme-link-initial-color, #59c3ff);
                font-size: 0.78rem;
                font-weight: 800;
                letter-spacing: 0;
                text-transform: uppercase;
            }

            .hrc-catalog-header h2 {
                margin: 0;
                color: var(--theme-headings-color, #ffffff);
                font-size: 2.25rem;
                line-height: 1.1;
                letter-spacing: 0;
            }

            .hrc-product-count {
                flex: 0 0 auto;
                color: #91a6bc;
                font-size: 0.9rem;
                font-weight: 700;
            }

            .hrc-product-grid {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 12px;
            }

            .hrc-search-wrap {
                margin-bottom: 16px;
            }

            .hrc-search {
                width: 100%;
                height: 46px;
                padding: 0 14px;
                color: var(--theme-text-color, #e7edf3);
                background: #111a24 !important;
                border: 1px solid var(--theme-border-color, #2a3340);
                border-radius: 6px;
                box-shadow: none;
                font: inherit;
                letter-spacing: 0;
            }

            .hrc-search::placeholder {
                color: #7f93aa;
                opacity: 1;
            }

            .hrc-search:focus {
                border-color: var(--theme-link-initial-color, #59c3ff);
                box-shadow: 0 0 0 2px rgba(89, 195, 255, 0.18);
                outline: none;
            }

            .hrc-product-card {
                min-width: 0;
                margin: 0;
                overflow: hidden;
                background: #0e1319;
                border: 1px solid var(--theme-border-color, #2a3340);
                border-radius: 6px;
                box-shadow: 0 3px 0 rgba(0, 0, 0, 0.16);
                transition: transform 160ms ease, border-color 160ms ease, box-shadow 160ms ease;
            }

            .hrc-product-card:hover,
            .hrc-product-card:focus-within {
                transform: translateY(-2px);
                border-color: var(--theme-link-initial-color, #59c3ff);
                box-shadow: 0 10px 22px rgba(0, 0, 0, 0.32);
            }

            .hrc-product-link {
                display: flex;
                height: 100%;
                color: inherit;
                text-decoration: none !important;
                flex-direction: column;
            }

            .hrc-product-link:focus-visible {
                outline: 3px solid var(--theme-link-initial-color, #59c3ff);
                outline-offset: -3px;
            }

            .hrc-image-frame {
                display: flex;
                width: 100%;
                aspect-ratio: 4 / 3;
                align-items: center;
                justify-content: center;
                padding: 18px;
                background: #ffffff;
                border-bottom: 1px solid var(--theme-border-color, #2a3340);
            }

            .hrc-image-frame img {
                display: block;
                width: 100%;
                height: 100%;
                object-fit: contain;
            }

            .hrc-card-body {
                display: flex;
                flex: 1 1 auto;
                min-width: 0;
                padding: 15px;
                flex-direction: column;
            }

            .hrc-product-title {
                display: -webkit-box;
                min-height: 4.15em;
                overflow: hidden;
                color: var(--theme-headings-color, #ffffff);
                font-size: 1rem;
                font-weight: 750;
                line-height: 1.38;
                letter-spacing: 0;
                -webkit-box-orient: vertical;
                -webkit-line-clamp: 3;
            }

            .hrc-product-model {
                margin-top: 6px;
                overflow: hidden;
                color: #91a6bc;
                font-size: 0.78rem;
                font-weight: 650;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .hrc-card-footer {
                display: flex;
                align-items: flex-end;
                justify-content: space-between;
                gap: 12px;
                margin-top: auto;
                padding-top: 18px;
            }

            .hrc-price-wrap {
                display: flex;
                flex-direction: column;
            }

            .hrc-price-label {
                color: #91a6bc;
                font-size: 0.68rem;
                font-weight: 800;
                text-transform: uppercase;
            }

            .hrc-price {
                color: var(--theme-link-initial-color, #59c3ff);
                font-size: 1.35rem;
                font-weight: 850;
                line-height: 1.15;
            }

            .hrc-view-deal {
                flex: 0 0 auto;
                padding: 8px 10px;
                color: #08121b;
                background: var(--theme-link-initial-color, #59c3ff);
                border-radius: 5px;
                font-size: 0.78rem;
                font-weight: 800;
                line-height: 1;
                white-space: nowrap;
            }

            .hrc-empty-state {
                padding: 32px;
                color: var(--theme-text-color, #e7edf3);
                background: #0e1319;
                border: 1px solid var(--theme-border-color, #2a3340);
                border-radius: 6px;
                text-align: center;
            }

            .hrc-no-results {
                padding: 32px;
                color: var(--theme-text-color, #e7edf3);
                background: #0e1319;
                border: 1px solid var(--theme-border-color, #2a3340);
                border-radius: 6px;
                text-align: center;
            }

            @media (max-width: 1100px) {
                .hrc-product-grid {
                    grid-template-columns: repeat(3, minmax(0, 1fr));
                }
            }

            @media (max-width: 780px) {
                .hrc-catalog {
                    width: min(100% - 24px, 720px);
                    margin-top: 20px;
                }

                .hrc-product-grid {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }

                .hrc-image-frame {
                    padding: 12px;
                }

                .hrc-card-body {
                    padding: 12px;
                }

                .hrc-view-deal {
                    padding: 8px;
                }
            }

            @media (max-width: 520px) {
                .hrc-catalog-header {
                    align-items: flex-start;
                    flex-direction: column;
                    gap: 8px;
                }

                .hrc-product-grid {
                    grid-template-columns: minmax(0, 1fr);
                }

                .hrc-catalog-header h2 {
                    font-size: 1.75rem;
                }

                .hrc-product-title {
                    min-height: 0;
                    font-size: 1.02rem;
                    -webkit-line-clamp: 2;
                }

                .hrc-image-frame {
                    aspect-ratio: 16 / 10;
                }
            }
        </style>
        <?php
    }

    private static function sanitize_dealer_urls_option($raw)
    {
        $raw = (string) $raw;
        if ($raw === '') {
            return '';
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw);
        if (!is_array($lines)) {
            return '';
        }

        $valid_urls = array();
        foreach ($lines as $line) {
            $url = trim((string) $line);
            if ($url === '') {
                continue;
            }

            if (!preg_match('#^https?://#i', $url)) {
                $url = 'https://' . $url;
            }

            $url_for_validation = preg_replace('/\{sku\}/i', 'HS507COMP', $url);
            if (!is_string($url_for_validation)) {
                continue;
            }

            $validated = esc_url_raw($url_for_validation);
            if ($validated === '') {
                continue;
            }

            if (function_exists('wp_http_validate_url') && !wp_http_validate_url($validated)) {
                continue;
            }

            $url = preg_replace('/\{sku\}/i', '{sku}', $url);
            if (is_string($url)) {
                $valid_urls[$url] = true;
            }
        }

        return empty($valid_urls) ? '' : implode("\n", array_keys($valid_urls));
    }

    private static function get_dealer_urls_from_option($raw)
    {
        $sanitized = self::sanitize_dealer_urls_option($raw);
        if ($sanitized === '') {
            return array();
        }

        $lines = preg_split('/\r\n|\r|\n/', $sanitized);
        if (!is_array($lines)) {
            return array();
        }

        return array_values(array_filter(array_map('trim', $lines)));
    }

    private static function push_admin_notice($success, $message)
    {
        $key = 'hrc_notice_' . get_current_user_id();
        set_transient(
            $key,
            array(
                'success' => (bool) $success,
                'message' => (string) $message,
            ),
            MINUTE_IN_SECONDS
        );
    }

    private static function pull_admin_notice()
    {
        $key = 'hrc_notice_' . get_current_user_id();
        $notice = get_transient($key);
        delete_transient($key);

        return is_array($notice) ? $notice : array();
    }
}
