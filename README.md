# Holosun RSR Catalog

Standalone WordPress plugin that:

- Connects to RSR FTP/FTPS
- Downloads the product feed
- Filters to HOLOSUN-only products
- Stores products in a local DB table
- Applies configurable markup (default 10%)
- Renders a searchable, scrollable front-page product list

## WordPress Usage

1. Activate `Holosun RSR Catalog` in `Plugins`.
2. Go to `Settings -> Holosun RSR Catalog`.
3. Enter RSR FTP credentials and click `Sync Now`.
4. Use shortcode `[holosun_rsr_list]` anywhere, or rely on front-page auto-append behavior.

## Deploy to VPS (Git Pull Workflow)

Assuming WordPress plugin path:

`/var/www/holosundeals.com/public_html/wp-content/plugins/holosun-rsr-catalog`

### First-time clone on VPS

```bash
cd /var/www/holosundeals.com/public_html/wp-content/plugins
sudo rm -rf holosun-rsr-catalog
sudo git clone https://github.com/YOUR_GITHUB_USER/holosun-rsr-catalog.git
sudo chown -R www-data:www-data holosun-rsr-catalog
```

### Update on VPS

```bash
cd /var/www/holosundeals.com/public_html/wp-content/plugins/holosun-rsr-catalog
sudo git pull origin main
sudo chown -R www-data:www-data .
```

