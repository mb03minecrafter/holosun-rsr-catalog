<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Holosun_RSR_Catalog_Plugin
{
    const VERSION = '1.0.0';
    const DB_VERSION = '1.0.0';

    const OPTION_SETTINGS = 'hrc_settings';
    const OPTION_DB_VERSION = 'hrc_db_version';
    const OPTION_LAST_SYNC = 'hrc_last_sync';
    const OPTION_LAST_SYNC_STATUS = 'hrc_last_sync_status';
    const OPTION_LAST_SYNC_MESSAGE = 'hrc_last_sync_message';
    const OPTION_LAST_SYNC_COUNT = 'hrc_last_sync_count';

    const ADMIN_SLUG = 'holosun-rsr-catalog';
    const CRON_HOOK = 'hrc_sync_event';
    const SHORTCODE = 'holosun_rsr_list';

    /**
     * @var bool
     */
    private static $booted = false;

    /**
     * @var bool
     */
    private static $assets_printed = false;

    public static function boot()
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        register_activation_hook(HRC_PLUGIN_FILE, array(__CLASS__, 'activate'));
        register_deactivation_hook(HRC_PLUGIN_FILE, array(__CLASS__, 'deactivate'));

        add_action('init', array(__CLASS__, 'maybe_upgrade_schema'));
        add_action('admin_menu', array(__CLASS__, 'register_admin_menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('admin_post_hrc_sync_now', array(__CLASS__, 'handle_sync_now'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'run_scheduled_sync'));

        add_shortcode(self::SHORTCODE, array(__CLASS__, 'render_products_shortcode'));
        add_filter('the_content', array(__CLASS__, 'append_front_page_list'), 20);
    }

    public static function activate()
    {
        self::create_tables();
        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + (5 * MINUTE_IN_SECONDS), 'hourly', self::CRON_HOOK);
        }
    }

    public static function deactivate()
    {
        $next = wp_next_scheduled(self::CRON_HOOK);
        if ($next) {
            wp_unschedule_event($next, self::CRON_HOOK);
        }
    }

    public static function maybe_upgrade_schema()
    {
        $installed = get_option(self::OPTION_DB_VERSION, '');
        if ((string) $installed === self::DB_VERSION) {
            return;
        }

        self::create_tables();
        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
    }

    public static function register_admin_menu()
    {
        add_options_page(
            'Holosun RSR Catalog',
            'Holosun RSR Catalog',
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

        $host = isset($input['ftp_host']) ? trim((string) wp_unslash($input['ftp_host'])) : '';
        $username = isset($input['ftp_username']) ? trim((string) wp_unslash($input['ftp_username'])) : '';
        $password = isset($input['ftp_password']) ? trim((string) wp_unslash($input['ftp_password'])) : '';
        $account_code = isset($input['account_code']) ? trim((string) wp_unslash($input['account_code'])) : '';
        $port = isset($input['ftp_port']) ? absint($input['ftp_port']) : 2222;
        $remote_path = isset($input['remote_path']) ? trim((string) wp_unslash($input['remote_path'])) : '/ftpdownloads/rsrinventory-new.zip';
        $markup_percent = isset($input['markup_percent']) ? (float) $input['markup_percent'] : 10.0;
        $use_ssl = !empty($input['ftp_use_ssl']) ? '1' : '0';

        if ($port <= 0 || $port > 65535) {
            $port = 2222;
        }

        if ($remote_path === '') {
            $remote_path = '/ftpdownloads/rsrinventory-new.zip';
        }

        if ($markup_percent < 0) {
            $markup_percent = 0;
        }
        if ($markup_percent > 1000) {
            $markup_percent = 1000;
        }

        return array(
            'ftp_host' => sanitize_text_field($host),
            'ftp_username' => sanitize_text_field($username),
            'ftp_password' => $password,
            'account_code' => sanitize_text_field($account_code),
            'ftp_port' => (string) $port,
            'ftp_use_ssl' => $use_ssl,
            'remote_path' => sanitize_text_field($remote_path),
            'markup_percent' => (string) $markup_percent,
        );
    }

    public static function get_settings()
    {
        $defaults = array(
            'ftp_host' => '',
            'ftp_username' => '',
            'ftp_password' => '',
            'account_code' => '',
            'ftp_port' => '2222',
            'ftp_use_ssl' => '1',
            'remote_path' => '/ftpdownloads/rsrinventory-new.zip',
            'markup_percent' => '10',
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
            <h1>Holosun RSR Catalog</h1>
            <p>This plugin imports <strong>HOLOSUN-only</strong> products from your RSR FTP feed and applies a markup to distributor price.</p>
            <p>Shortcode: <code>[<?php echo esc_html(self::SHORTCODE); ?>]</code></p>

            <?php if (!empty($notice)) : ?>
                <div class="notice <?php echo !empty($notice['success']) ? 'notice-success' : 'notice-error'; ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($last_sync !== '') : ?>
                <div class="notice notice-info">
                    <p>
                        Last sync: <strong><?php echo esc_html($last_sync); ?></strong><br>
                        Status: <strong><?php echo esc_html($last_status !== '' ? $last_status : 'n/a'); ?></strong><br>
                        Imported HOLOSUN rows: <strong><?php echo esc_html((string) $last_count); ?></strong><br>
                        Message: <?php echo esc_html($last_message !== '' ? $last_message : 'n/a'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('hrc_settings_group'); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                    <tr>
                        <th scope="row"><label for="hrc_ftp_host">FTP Host</label></th>
                        <td><input name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[ftp_host]" id="hrc_ftp_host" type="text" class="regular-text" value="<?php echo esc_attr($settings['ftp_host']); ?>" placeholder="ftps.rsrgroup.com"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hrc_ftp_username">FTP Username</label></th>
                        <td><input name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[ftp_username]" id="hrc_ftp_username" type="text" class="regular-text" value="<?php echo esc_attr($settings['ftp_username']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hrc_ftp_password">FTP Password</label></th>
                        <td><input name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[ftp_password]" id="hrc_ftp_password" type="password" class="regular-text" value="<?php echo esc_attr($settings['ftp_password']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hrc_account_code">RSR Code</label></th>
                        <td>
                            <input name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[account_code]" id="hrc_account_code" type="text" class="regular-text" value="<?php echo esc_attr($settings['account_code']); ?>">
                            <p class="description">Optional reference field for your RSR account/dealer code.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hrc_ftp_port">FTP Port</label></th>
                        <td><input name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[ftp_port]" id="hrc_ftp_port" type="number" min="1" max="65535" value="<?php echo esc_attr($settings['ftp_port']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Use FTPS (SSL)</th>
                        <td>
                            <label>
                                <input name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[ftp_use_ssl]" type="checkbox" value="1" <?php checked($settings['ftp_use_ssl'], '1'); ?>>
                                Enable secure FTP (recommended)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hrc_remote_path">Remote Feed Path</label></th>
                        <td>
                            <input name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[remote_path]" id="hrc_remote_path" type="text" class="regular-text" value="<?php echo esc_attr($settings['remote_path']); ?>">
                            <p class="description">Default: <code>/ftpdownloads/rsrinventory-new.zip</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hrc_markup_percent">Markup Percent</label></th>
                        <td>
                            <input name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[markup_percent]" id="hrc_markup_percent" type="number" step="0.01" min="0" value="<?php echo esc_attr($settings['markup_percent']); ?>">
                            <p class="description">Displayed price = distributor price * (1 + markup/100). Example: 10 = 10% markup.</p>
                        </td>
                    </tr>
                    </tbody>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>

            <hr>

            <h2>Sync</h2>
            <p>Manual sync downloads the feed, filters to HOLOSUN rows, and updates the local database table.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="hrc_sync_now">
                <?php wp_nonce_field('hrc_sync_now'); ?>
                <?php submit_button('Sync Now', 'primary', 'submit', false); ?>
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

        $result = self::sync_from_ftp();
        self::push_admin_notice(!empty($result['success']), isset($result['message']) ? (string) $result['message'] : 'Sync finished.');

        wp_safe_redirect(admin_url('options-general.php?page=' . self::ADMIN_SLUG));
        exit;
    }

    public static function run_scheduled_sync()
    {
        self::sync_from_ftp();
    }

    public static function sync_from_ftp()
    {
        $settings = self::get_settings();
        $host = trim((string) $settings['ftp_host']);
        $username = trim((string) $settings['ftp_username']);
        $password = (string) $settings['ftp_password'];

        if ($host === '' || $username === '' || $password === '') {
            return self::finalize_sync(false, 0, 'Missing FTP settings. Please set host, username, and password.');
        }

        $download = self::download_feed_file($settings);
        if (empty($download['success'])) {
            return self::finalize_sync(false, 0, isset($download['message']) ? $download['message'] : 'Feed download failed.');
        }

        $txt_path = isset($download['txt_path']) ? (string) $download['txt_path'] : '';
        if ($txt_path === '' || !file_exists($txt_path)) {
            return self::finalize_sync(false, 0, 'Feed file was downloaded but no text file was found.');
        }

        $import = self::import_holosun_rows_from_file($txt_path, $settings);
        return self::finalize_sync(!empty($import['success']), (int) $import['count'], (string) $import['message']);
    }

    private static function finalize_sync($success, $count, $message)
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

    private static function download_feed_file($settings)
    {
        if (!function_exists('ftp_connect')) {
            return array(
                'success' => false,
                'message' => 'PHP FTP extension is not available on this server.',
            );
        }

        $host = trim((string) $settings['ftp_host']);
        $username = trim((string) $settings['ftp_username']);
        $password = (string) $settings['ftp_password'];
        $port = isset($settings['ftp_port']) ? absint($settings['ftp_port']) : 2222;
        $use_ssl = !empty($settings['ftp_use_ssl']);
        $remote_path = trim((string) $settings['remote_path']);

        if ($port <= 0 || $port > 65535) {
            $port = 2222;
        }

        if ($remote_path === '') {
            $remote_path = '/ftpdownloads/rsrinventory-new.zip';
        }

        $conn = null;
        if ($use_ssl && function_exists('ftp_ssl_connect')) {
            $conn = @ftp_ssl_connect($host, $port, 30);
        }
        if (!$conn) {
            $conn = @ftp_connect($host, $port, 30);
        }

        if (!$conn) {
            return array(
                'success' => false,
                'message' => 'Could not connect to FTP host.',
            );
        }

        $logged_in = @ftp_login($conn, $username, $password);
        if (!$logged_in) {
            @ftp_close($conn);
            return array(
                'success' => false,
                'message' => 'FTP login failed. Please verify username/password.',
            );
        }

        @ftp_pasv($conn, true);

        $uploads = wp_upload_dir();
        $base_dir = trailingslashit($uploads['basedir']) . 'holosun-rsr-catalog';
        if (!wp_mkdir_p($base_dir)) {
            @ftp_close($conn);
            return array(
                'success' => false,
                'message' => 'Could not create uploads directory for feed files.',
            );
        }

        $remote_name = basename($remote_path);
        if ($remote_name === '' || $remote_name === '.' || $remote_name === DIRECTORY_SEPARATOR) {
            $remote_name = 'rsrinventory-new.zip';
        }

        $local_file = trailingslashit($base_dir) . $remote_name;
        $tmp_file = $local_file . '.tmp';
        @unlink($tmp_file);

        $downloaded = @ftp_get($conn, $tmp_file, $remote_path, FTP_BINARY);
        @ftp_close($conn);

        if (!$downloaded) {
            @unlink($tmp_file);
            return array(
                'success' => false,
                'message' => 'FTP download failed. Check remote path and FTP permissions.',
            );
        }

        if (!@rename($tmp_file, $local_file)) {
            @unlink($tmp_file);
            return array(
                'success' => false,
                'message' => 'Downloaded file could not be finalized on disk.',
            );
        }

        $lower = strtolower($local_file);
        if (self::string_ends_with($lower, '.zip')) {
            $extract = self::extract_zip_file($local_file, $base_dir);
            if (empty($extract['success'])) {
                return $extract;
            }

            return array(
                'success' => true,
                'txt_path' => isset($extract['txt_path']) ? (string) $extract['txt_path'] : '',
                'message' => 'Feed ZIP downloaded and extracted.',
            );
        }

        return array(
            'success' => true,
            'txt_path' => $local_file,
            'message' => 'Feed text file downloaded.',
        );
    }

    private static function extract_zip_file($zip_path, $extract_dir)
    {
        if (!class_exists('ZipArchive')) {
            return array(
                'success' => false,
                'message' => 'ZipArchive is not available on this server.',
            );
        }

        $zip = new ZipArchive();
        $opened = $zip->open($zip_path);
        if ($opened !== true) {
            return array(
                'success' => false,
                'message' => 'Could not open downloaded ZIP file.',
            );
        }

        $txt_entry = '';
        $file_count = (int) $zip->numFiles;
        for ($i = 0; $i < $file_count; $i++) {
            $entry_name = (string) $zip->getNameIndex($i);
            if (self::string_ends_with(strtolower($entry_name), '.txt')) {
                $txt_entry = $entry_name;
                break;
            }
        }

        if ($txt_entry === '') {
            $zip->close();
            return array(
                'success' => false,
                'message' => 'ZIP file did not contain a .txt feed file.',
            );
        }

        if (!$zip->extractTo($extract_dir, array($txt_entry))) {
            $zip->close();
            return array(
                'success' => false,
                'message' => 'ZIP extraction failed.',
            );
        }
        $zip->close();

        $candidate = trailingslashit($extract_dir) . ltrim($txt_entry, '/\\');
        if (!file_exists($candidate)) {
            $candidate = trailingslashit($extract_dir) . basename($txt_entry);
        }

        if (!file_exists($candidate)) {
            return array(
                'success' => false,
                'message' => 'Extracted feed text file could not be located.',
            );
        }

        return array(
            'success' => true,
            'txt_path' => $candidate,
        );
    }

    private static function import_holosun_rows_from_file($txt_path, $settings)
    {
        global $wpdb;

        $table = self::get_table_name();
        self::create_tables();

        $markup_percent = isset($settings['markup_percent']) ? (float) $settings['markup_percent'] : 10.0;
        if ($markup_percent < 0) {
            $markup_percent = 0;
        }
        $multiplier = 1 + ($markup_percent / 100);

        $sync_token = wp_generate_password(20, false, false);
        $now = current_time('mysql', true);

        $handle = @fopen($txt_path, 'rb');
        if (!$handle) {
            return array(
                'success' => false,
                'count' => 0,
                'message' => 'Could not read downloaded feed file.',
            );
        }

        $line_number = 0;
        $holosun_rows = 0;
        $upserted_rows = 0;
        $invalid_rows = 0;

        while (($line = fgets($handle)) !== false) {
            $line_number++;
            $parsed = self::parse_feed_line($line, $line_number);

            if ($parsed === null) {
                $invalid_rows++;
                continue;
            }

            if (!self::is_holosun_manufacturer($parsed['manufacturer'])) {
                continue;
            }

            $holosun_rows++;

            $distributor_price = (float) $parsed['distributor_price'];
            $display_price = round($distributor_price * $multiplier, 2);

            $replace_result = $wpdb->replace(
                $table,
                array(
                    'rsr_sku' => $parsed['rsr_sku'],
                    'upc' => $parsed['upc'],
                    'manufacturer' => $parsed['manufacturer'],
                    'manufacturer_part_number' => $parsed['manufacturer_part_number'],
                    'product_name' => $parsed['product_name'],
                    'distributor_price' => $distributor_price,
                    'display_price' => $display_price,
                    'inventory_quantity' => $parsed['inventory_quantity'],
                    'allocation_status' => $parsed['allocation_status'],
                    'sync_token' => $sync_token,
                    'last_seen_at' => $now,
                    'updated_at' => $now,
                ),
                array(
                    '%s', // rsr_sku
                    '%s', // upc
                    '%s', // manufacturer
                    '%s', // manufacturer_part_number
                    '%s', // product_name
                    '%f', // distributor_price
                    '%f', // display_price
                    '%d', // inventory_quantity
                    '%s', // allocation_status
                    '%s', // sync_token
                    '%s', // last_seen_at
                    '%s', // updated_at
                )
            );

            if ($replace_result !== false) {
                $upserted_rows++;
            }
        }

        fclose($handle);

        if ($holosun_rows > 0) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table} WHERE sync_token <> %s",
                    $sync_token
                )
            );
        }

        if ($upserted_rows <= 0) {
            return array(
                'success' => false,
                'count' => 0,
                'message' => 'Sync completed but no HOLOSUN rows were imported. Check feed data and brand filter.',
            );
        }

        return array(
            'success' => true,
            'count' => (int) $upserted_rows,
            'message' => sprintf(
                'Imported %d HOLOSUN rows (parsed: %d, skipped/invalid lines: %d).',
                (int) $upserted_rows,
                (int) $holosun_rows,
                (int) $invalid_rows
            ),
        );
    }

    private static function parse_feed_line($line, $line_number)
    {
        $line = trim((string) $line);
        if ($line === '') {
            return null;
        }

        $columns = explode(';', $line);
        if (!isset($columns[0])) {
            return null;
        }

        $first = trim((string) $columns[0]);
        if (
            (int) $line_number === 1 &&
            (stripos($first, 'RSR Stock') === 0 || stripos($first, 'RSR#') === 0)
        ) {
            return null;
        }

        if (count($columns) < 12) {
            return null;
        }

        $rsr_sku = trim((string) $columns[0]);
        if ($rsr_sku === '') {
            return null;
        }

        $product_name = isset($columns[2]) ? trim((string) $columns[2]) : '';
        if ($product_name === '') {
            $product_name = $rsr_sku;
        }
        $product_name = self::normalize_catalog_text($product_name);

        $manufacturer = isset($columns[10]) ? trim((string) $columns[10]) : '';
        $manufacturer = self::normalize_catalog_text($manufacturer);

        return array(
            'rsr_sku' => $rsr_sku,
            'upc' => isset($columns[1]) ? trim((string) $columns[1]) : '',
            'product_name' => $product_name,
            'manufacturer' => $manufacturer,
            'manufacturer_part_number' => isset($columns[11]) ? trim((string) $columns[11]) : '',
            'distributor_price' => self::parse_decimal(isset($columns[6]) ? $columns[6] : ''),
            'inventory_quantity' => self::parse_int_value(isset($columns[8]) ? $columns[8] : ''),
            'allocation_status' => isset($columns[12]) ? trim((string) $columns[12]) : '',
        );
    }

    private static function is_holosun_manufacturer($manufacturer)
    {
        $manufacturer = strtoupper((string) $manufacturer);
        $normalized = preg_replace('/[^A-Z0-9]/', '', $manufacturer);
        if (!is_string($normalized)) {
            return false;
        }

        return strpos($normalized, 'HOLOSUN') !== false;
    }

    private static function normalize_catalog_text($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/', ' ', $value);
        if (!is_string($value)) {
            return '';
        }

        // Normalize common abbreviated brand spellings to the preferred casing.
        $value = preg_replace('/\bH[\s\-]?SUN\b/i', 'Holosun', $value);
        if (!is_string($value)) {
            return '';
        }

        $tokens = preg_split('/(\s+)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($tokens)) {
            return trim($value);
        }

        foreach ($tokens as $idx => $token) {
            if (trim($token) === '') {
                continue;
            }

            $tokens[$idx] = self::normalize_catalog_token($token);
        }

        $normalized = trim(implode('', $tokens));
        $normalized = preg_replace('/\bH[\s\-]?Sun\b/i', 'Holosun', $normalized);
        if (!is_string($normalized)) {
            return trim($value);
        }

        return trim($normalized);
    }

    private static function normalize_catalog_token($token)
    {
        $token = (string) $token;

        if (!preg_match('/^([^A-Za-z0-9]*)([A-Za-z0-9\/\-]+)([^A-Za-z0-9]*)$/', $token, $matches)) {
            return $token;
        }

        $prefix = isset($matches[1]) ? (string) $matches[1] : '';
        $core = isset($matches[2]) ? (string) $matches[2] : '';
        $suffix = isset($matches[3]) ? (string) $matches[3] : '';

        if ($core === '') {
            return $token;
        }

        $parts = preg_split('/([\-\/])/', $core, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return $token;
        }

        foreach ($parts as $idx => $part) {
            if ($part === '-' || $part === '/') {
                continue;
            }
            $parts[$idx] = self::normalize_catalog_fragment($part);
        }

        return $prefix . implode('', $parts) . $suffix;
    }

    private static function normalize_catalog_fragment($fragment)
    {
        $fragment = trim((string) $fragment);
        if ($fragment === '') {
            return '';
        }

        $upper = strtoupper($fragment);

        // Terms that must stay all-caps.
        $always_upper = array('IRIS', 'EPS', 'EVO', 'ARO', 'MRS', 'DRS', 'DPS', 'TH', 'AEMS', 'SCS');
        if (in_array($upper, $always_upper, true)) {
            return $upper;
        }

        // Color abbreviations should be expanded to words.
        $color_map = array(
            'RD' => 'Red',
            'GD' => 'Gold',
            'GR' => 'Green',
        );
        if (isset($color_map[$upper])) {
            return $color_map[$upper];
        }

        if ($upper === 'HOLOSUN') {
            return 'Holosun';
        }

        // Keep mixed alphanumeric model codes in uppercase (e.g., HS510C, 2MOA).
        if (preg_match('/[0-9]/', $upper) === 1) {
            return $upper;
        }

        if (preg_match('/^[A-Z]+$/', $upper) === 1) {
            return ucfirst(strtolower($upper));
        }

        if (preg_match('/^[a-z]+$/', $fragment) === 1) {
            return ucfirst($fragment);
        }

        return ucfirst(strtolower($fragment));
    }

    private static function parse_decimal($value)
    {
        $value = (string) $value;
        $normalized = preg_replace('/[^0-9\.\-]/', '', $value);
        if (!is_string($normalized) || $normalized === '' || $normalized === '.' || $normalized === '-') {
            return 0.0;
        }

        return (float) $normalized;
    }

    private static function parse_int_value($value)
    {
        $value = (string) $value;
        $normalized = preg_replace('/[^0-9\-]/', '', $value);
        if (!is_string($normalized) || $normalized === '' || $normalized === '-') {
            return 0;
        }

        return (int) $normalized;
    }

    public static function append_front_page_list($content)
    {
        if (is_admin()) {
            return $content;
        }

        if (!is_main_query() || !in_the_loop()) {
            return $content;
        }

        if (!is_front_page()) {
            return $content;
        }

        if (has_shortcode((string) $content, self::SHORTCODE)) {
            return $content;
        }

        return (string) $content . "\n\n" . self::render_products_shortcode(array());
    }

    public static function render_products_shortcode($atts = array())
    {
        global $wpdb;

        $table = self::get_table_name();
        $rows = $wpdb->get_results(
            "SELECT rsr_sku, product_name, display_price
             FROM {$table}
             ORDER BY display_price DESC, product_name ASC"
        );

        $container_id = 'hrc-list-' . wp_rand(1000, 999999);

        ob_start();
        self::print_frontend_assets();
        ?>
        <section class="hrc-catalog-wrap" id="<?php echo esc_attr($container_id); ?>">
            <div class="hrc-head">
                <h2>Holosun Product Lookup</h2>
            </div>

            <div class="hrc-search-row">
                <input type="search" class="hrc-search" placeholder="Search by name or SKU">
                <span class="hrc-count"></span>
            </div>

            <div class="hrc-scroll">
                <ul class="hrc-list">
                    <?php if (!empty($rows)) : ?>
                        <?php foreach ($rows as $row) : ?>
                            <?php
                            $name = isset($row->product_name) ? (string) $row->product_name : '';
                            $name = self::normalize_catalog_text($name);
                            $sku = isset($row->rsr_sku) ? (string) $row->rsr_sku : '';
                            $display_price = isset($row->display_price) ? (float) $row->display_price : 0;
                            $search_blob = strtolower(trim($name . ' ' . $sku));
                            ?>
                            <li class="hrc-item" data-search="<?php echo esc_attr($search_blob); ?>">
                                <div class="hrc-item-main">
                                    <div class="hrc-item-name"><?php echo esc_html($name); ?></div>
                                    <div class="hrc-item-meta">
                                        SKU: <?php echo esc_html($sku); ?>
                                    </div>
                                </div>
                                <div class="hrc-item-price">$<?php echo esc_html(number_format($display_price, 2)); ?></div>
                            </li>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <li class="hrc-empty">No Holosun products available yet. Run a sync from Settings -> Holosun RSR Catalog.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </section>
        <script>
            (function () {
                var root = document.getElementById(<?php echo wp_json_encode($container_id); ?>);
                if (!root) {
                    return;
                }
                var search = root.querySelector('.hrc-search');
                var count = root.querySelector('.hrc-count');
                var items = Array.prototype.slice.call(root.querySelectorAll('.hrc-item'));
                var total = items.length;
                var apply = function () {
                    var query = '';
                    if (search && typeof search.value === 'string') {
                        query = search.value.toLowerCase().trim();
                    }
                    var visible = 0;
                    items.forEach(function (item) {
                        var haystack = (item.getAttribute('data-search') || '').toLowerCase();
                        var match = !query || haystack.indexOf(query) !== -1;
                        item.style.display = match ? 'flex' : 'none';
                        if (match) {
                            visible++;
                        }
                    });
                    if (count) {
                        count.textContent = visible + ' / ' + total + ' products';
                    }
                };
                if (search) {
                    search.addEventListener('input', apply);
                }
                apply();
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
            .hrc-catalog-wrap {
                background: #0e1319;
                color: #e7edf3;
                border: 1px solid #202c3a;
                border-radius: 14px;
                padding: 18px;
                margin-top: 24px;
            }
            .hrc-head h2 {
                margin: 0;
                color: #ffffff;
                font-size: 1.35rem;
                line-height: 1.25;
            }
            .hrc-search-row {
                margin-top: 14px;
                margin-bottom: 12px;
                display: flex;
                gap: 10px;
                align-items: center;
                flex-wrap: wrap;
            }
            .hrc-search {
                flex: 1 1 260px;
                min-width: 240px;
                background: #111a24;
                border: 1px solid #2b3b4d;
                border-radius: 8px;
                color: #e7edf3;
                padding: 10px 12px;
            }
            .hrc-search::placeholder {
                color: #7f93aa;
            }
            .hrc-count {
                color: #91a6bc;
                font-size: 0.9rem;
            }
            .hrc-scroll {
                max-height: 360px;
                overflow-y: auto;
                border: 1px solid #223141;
                border-radius: 10px;
                background: #0d161f;
            }
            .hrc-list {
                list-style: none;
                margin: 0;
                padding: 0;
            }
            .hrc-item {
                display: flex;
                justify-content: space-between;
                gap: 12px;
                align-items: center;
                padding: 12px 14px;
                border-bottom: 1px solid #1a2633;
            }
            .hrc-item:last-child {
                border-bottom: 0;
            }
            .hrc-item-name {
                color: #ffffff;
                font-weight: 600;
                line-height: 1.35;
            }
            .hrc-item-meta {
                margin-top: 4px;
                font-size: 0.82rem;
                color: #8ca0b4;
                word-break: break-word;
            }
            .hrc-item-price {
                color: #73d2ff;
                font-weight: 700;
                white-space: nowrap;
            }
            .hrc-empty {
                padding: 14px;
                color: #95a9be;
            }
            @media (max-width: 700px) {
                .hrc-item {
                    flex-direction: column;
                    align-items: flex-start;
                }
                .hrc-item-price {
                    margin-top: 6px;
                }
            }
        </style>
        <?php
    }

    private static function create_tables()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::get_table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rsr_sku VARCHAR(64) NOT NULL,
            upc VARCHAR(32) NULL,
            manufacturer VARCHAR(255) NULL,
            manufacturer_part_number VARCHAR(128) NULL,
            product_name VARCHAR(500) NOT NULL,
            distributor_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            display_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            inventory_quantity INT NOT NULL DEFAULT 0,
            allocation_status VARCHAR(64) NULL,
            sync_token VARCHAR(32) NOT NULL DEFAULT '',
            last_seen_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY rsr_sku (rsr_sku),
            KEY manufacturer_part_number (manufacturer_part_number),
            KEY upc (upc)
        ) {$charset};";

        dbDelta($sql);
    }

    private static function get_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'holosun_rsr_products';
    }

    private static function push_admin_notice($success, $message)
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }

        set_transient(
            'hrc_sync_notice_' . $user_id,
            array(
                'success' => (bool) $success,
                'message' => (string) $message,
            ),
            120
        );
    }

    private static function pull_admin_notice()
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return array();
        }

        $key = 'hrc_sync_notice_' . $user_id;
        $notice = get_transient($key);
        delete_transient($key);

        if (!is_array($notice)) {
            return array();
        }

        return $notice;
    }

    private static function string_ends_with($haystack, $needle)
    {
        $haystack = (string) $haystack;
        $needle = (string) $needle;

        if ($needle === '') {
            return true;
        }

        return substr($haystack, -strlen($needle)) === $needle;
    }
}
