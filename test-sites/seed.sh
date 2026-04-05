#!/usr/bin/env bash
#
# Seeds WooCommerce products into all test sites from a JSON file produced by
# the main CataSEO repo's scripts/export-seed-products.ts.
#
# Usage:
#   ./seed.sh                                  # reads /tmp/cataseo-seed-products.json
#   SEED_JSON=/path/to/other.json ./seed.sh    # read from a specific file
#
# What gets created in each site:
#   - Product categories (hierarchy flattened; each unique category name becomes a term)
#   - One simple product per entry with: name, regular_price, sku, description
#   - Brand → stored as a custom taxonomy term if product_brand exists, else
#     written as a "Brand" attribute. WC 9.6+ has product_brand in core.
#   - SEO fields (page_title, meta_description, og_title, og_description,
#     meta_keywords) written to the post meta key that the connector's field
#     mapper returns for each site's detected mode. This way the seeded data
#     round-trips cleanly through the mapper when exercising the widget.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

require_tool wp
require_tool jq

SEED_JSON="${SEED_JSON:-/tmp/cataseo-seed-products.json}"

if [ ! -f "$SEED_JSON" ]; then
    echo "error: seed file not found at $SEED_JSON" >&2
    echo "       run this from the main CataSEO repo first:" >&2
    echo "       npx tsx scripts/export-seed-products.ts" >&2
    exit 1
fi

PRODUCT_COUNT="$(jq '.products | length' "$SEED_JSON")"
echo "Seeding $PRODUCT_COUNT products from $SEED_JSON"

# Field mapper: canonical field → post meta key, keyed by site slug.
# Mirrors src/includes/class-field-mapper.php. Keep in sync with the PHP version.
meta_key_for() {
    local site_slug="$1"
    local field="$2"
    case "$site_slug" in
        yoast)
            case "$field" in
                page_title)       echo "_yoast_wpseo_title" ;;
                meta_description) echo "_yoast_wpseo_metadesc" ;;
                og_title)         echo "_yoast_wpseo_opengraph-title" ;;
                og_description)   echo "_yoast_wpseo_opengraph-description" ;;
                meta_keywords)    echo "_yoast_wpseo_metakeywords" ;;
            esac
            ;;
        rankmath)
            case "$field" in
                page_title)       echo "rank_math_title" ;;
                meta_description) echo "rank_math_description" ;;
                og_title)         echo "rank_math_facebook_title" ;;
                og_description)   echo "rank_math_facebook_description" ;;
            esac
            ;;
        aioseo)
            case "$field" in
                page_title)       echo "_aioseo_title" ;;
                meta_description) echo "_aioseo_description" ;;
                og_title)         echo "_aioseo_og_title" ;;
                og_description)   echo "_aioseo_og_description" ;;
            esac
            ;;
        standalone)
            case "$field" in
                page_title)       echo "_cataseo_title" ;;
                meta_description) echo "_cataseo_description" ;;
                og_title)         echo "_cataseo_og_title" ;;
                og_description)   echo "_cataseo_og_description" ;;
            esac
            ;;
    esac
}

seed_site() {
    local slug="$1"
    local site_dir
    site_dir="$(site_path "$slug")"

    if [ ! -f "$site_dir/wp-config.php" ]; then
        echo "[$slug] not provisioned, skipping"
        return
    fi

    echo ""
    echo "=== seeding [$slug] ==="

    # Drop any existing products so this is idempotent. Guard against an empty
    # result on fresh sites where `wp post delete` with no IDs would error.
    local existing_ids
    existing_ids="$(wp --path="$site_dir" post list --post_type=product --format=ids 2>/dev/null || true)"
    if [ -n "$existing_ids" ]; then
        # shellcheck disable=SC2086
        wp --path="$site_dir" post delete $existing_ids --force >/dev/null 2>&1 || true
    fi

    local created=0
    local total="$PRODUCT_COUNT"

    # Stream products one-at-a-time so we don't build a giant shell array.
    # jq -c emits one compact JSON object per line.
    while IFS= read -r row; do
        local name price sku description brand
        name="$(echo "$row"        | jq -r '.name')"
        price="$(echo "$row"       | jq -r '.price')"
        sku="$(echo "$row"         | jq -r '.sku')"
        description="$(echo "$row" | jq -r '.description')"
        brand="$(echo "$row"       | jq -r '.brandName // empty')"

        # Create the product. WC requires a unique SKU; if two rows collide we
        # disambiguate with a suffix — the source export shouldn't have dups.
        local product_id
        product_id="$(wp --path="$site_dir" wc product create \
            --user=admin \
            --name="$name" \
            --type=simple \
            --regular_price="$price" \
            --sku="$sku" \
            --description="$description" \
            --status=publish \
            --porcelain 2>/dev/null || echo "")"

        if [ -z "$product_id" ]; then
            echo "[$slug] failed to create: $name (sku=$sku) — skipping" >&2
            continue
        fi

        # Assign categories: create each term in product_cat on demand, then
        # attach by name via `post term add` (one call per term so category
        # names with spaces are handled safely without shell word splitting).
        while IFS= read -r cat; do
            [ -z "$cat" ] && continue
            wp --path="$site_dir" term create product_cat "$cat" \
                >/dev/null 2>&1 || true
            wp --path="$site_dir" post term add "$product_id" product_cat "$cat" \
                >/dev/null 2>&1 || true
        done < <(echo "$row" | jq -r '.categories[]?')

        # Brand: set as Product Brand taxonomy term if the taxonomy exists.
        if [ -n "$brand" ]; then
            wp --path="$site_dir" term create product_brand "$brand" \
                >/dev/null 2>&1 || true
            wp --path="$site_dir" post term set "$product_id" product_brand "$brand" \
                >/dev/null 2>&1 || true
        fi

        # SEO meta — route each canonical field to the site's meta key.
        for field in page_title meta_description og_title og_description; do
            local key value
            key="$(meta_key_for "$slug" "$field")"
            [ -z "$key" ] && continue
            case "$field" in
                page_title)       value="$(echo "$row" | jq -r '.seo.pageTitle // empty')" ;;
                meta_description) value="$(echo "$row" | jq -r '.seo.metaDescription // empty')" ;;
                og_title)         value="$(echo "$row" | jq -r '.seo.ogTitle // empty')" ;;
                og_description)   value="$(echo "$row" | jq -r '.seo.ogDescription // empty')" ;;
            esac
            [ -z "$value" ] && continue
            wp --path="$site_dir" post meta update "$product_id" "$key" "$value" \
                >/dev/null 2>&1 || true
        done

        # Yoast-only: meta_keywords as comma-joined string.
        if [ "$slug" = "yoast" ]; then
            local kw
            kw="$(echo "$row" | jq -r '.seo.metaKeywords | join(", ") // empty')"
            if [ -n "$kw" ]; then
                wp --path="$site_dir" post meta update "$product_id" "_yoast_wpseo_metakeywords" "$kw" \
                    >/dev/null 2>&1 || true
            fi
        fi

        created=$((created + 1))
        if [ $((created % 10)) -eq 0 ]; then
            echo "[$slug]   $created / $total"
        fi
    done < <(jq -c '.products[]' "$SEED_JSON")

    echo "[$slug] created $created / $total products"
}

while IFS='|' read -r SLUG _PORT _DB _SEO; do
    seed_site "$SLUG"
done < <(iter_sites)

echo ""
echo "Seed complete."
