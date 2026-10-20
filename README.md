# Holosun Phoenix Catalog

Standalone WordPress plugin for `holosundeals.com` that:

- Reads published Holosun product identity, inventory, images, and URLs from Phoenix
- Reads Phoenix's current WooCommerce final sale price in bulk
- Displays a stable, product-specific 1-3% price uplift
- Keeps the last complete catalog available if Phoenix is temporarily unavailable
- Refreshes its server-side cache every five minutes
- Renders a responsive, searchable product catalog grouped into crawlable shopping categories
- Routes normal clicks to Deerford Defense 80% of the time and a random configured partner dealer 20% of the time

## WordPress Usage

1. Activate `Holosun Phoenix Catalog` in `Plugins`.
2. Go to `Settings -> Holosun Phoenix Catalog`.
3. Keep the Phoenix API URL set to `http://127.0.0.1:8080` when both applications share the VPS.
4. Add one partner dealer search URL template per line and click `Refresh Now`.
5. Use shortcode `[holosun_rsr_list]` anywhere, or rely on front-page auto-append behavior.

The plugin only publishes cards with an exact `Holosun` brand match, a published WooCommerce product, positive Phoenix inventory, an image, a Deerford permalink, and a valid Phoenix final sale price.

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

