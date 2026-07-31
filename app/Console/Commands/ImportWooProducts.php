<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Import WooCommerce products into Bagisto from the WooCommerce product CSV export
 * (Products → Export in wp-admin).
 *
 * Design (no band-aid): products are created through Bagisto's official
 * ProductRepository / type instances, so product_flat, EAV attribute values,
 * inventories, images and the price/flat indexers all run exactly as they would
 * from the admin panel. Categories are created/mapped through CategoryRepository.
 *
 * SCOPE of this command (v1 — the clean e-commerce catalog):
 *   - simple                       → Bagisto "simple"
 *   - simple, virtual              → Bagisto "virtual"
 *   - simple, downloadable(+virtual) → Bagisto "downloadable" (files re-hosted)
 *
 * DEFERRED / SKIPPED (reported, not imported — see DEPLOYMENT_RUNBOOK §21.2):
 *   - variable / variation  → configurable (blocked: export is missing variation
 *                             SKUs + attribute values; needs a proper re-export)
 *   - bundle                → Bagisto bundle (needs children imported first)
 *   - external              → no native Bagisto type
 *   - Event Tickets / Registration (FooEvents/Tribe) → Bagisto Booking (separate)
 *   - Donations / Name-Your-Price  → live in GiveWP, not the store
 *   - Subscriptions         → legacy, handled separately
 *
 * Idempotent: skips SKUs that already exist, so it is safe to re-run.
 */
class ImportWooProducts extends Command
{
    protected $signature = 'woo:import-products
        {file : Absolute path to the WooCommerce product CSV export}
        {--offset=0 : Skip this many data rows before importing}
        {--limit=0 : Import at most this many products (0 = no limit)}
        {--default-qty=1000 : Stock qty to use when Woo has no tracked stock but the item is "in stock"}
        {--skip-images : Do not download/attach product images}
        {--skip-files : Do not download/attach downloadable files}
        {--max-file-mb=300 : Re-host downloadable files up to this size (MB); larger files are kept as URL links}
        {--rehost-remote : Also re-host files on persistent hosts (e.g. S3) instead of keeping them as URL links}
        {--include-bundles : Import fixed-price WC Product Bundles as SIMPLE products (contents listed in the description)}
        {--dry-run : Parse, classify and report without writing anything}';

    protected $description = 'Import WooCommerce products (simple, virtual, downloadable) from a CSV export';

    protected ?int $attributeFamilyId = null;

    protected ?int $channelId = null;

    protected ?int $inventorySourceId = null;

    protected ?int $rootCategoryId = null;

    protected ?int $urlKeyAttributeId = null;

    /** @var array<string, true> Lowercased SKUs already present in Bagisto. */
    protected array $existingSkus = [];

    /** @var array<string, int> slug-path => category_id (existing + created this run). */
    protected array $categoryBySlugPath = [];

    /** @var array<string, true> url_keys already used (existing + assigned this run). */
    protected array $usedUrlKeys = [];

    /** @var array<string, string> sku(lower) => product name, from the CSV (for bundle contents). */
    protected array $productNames = [];

    /**
     * Woo top-level categories that must NOT become store products.
     * (Donations live in GiveWP; tickets/registration go to Booking later.)
     *
     * @var array<int, string>
     */
    protected array $excludedCategories = [
        'Donations',
        'Tree Sponsorships',
        'Memorial Olive Grove',
        'Become a Covenant Partner',
        'Event Tickets',
        'Event Registration',
    ];

    public function handle(): int
    {
        $file = $this->argument('file');

        if (! is_file($file) || ! is_readable($file)) {
            $this->error("File not found or not readable: {$file}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $offset = max(0, (int) $this->option('offset'));
        $limit = max(0, (int) $this->option('limit'));

        $this->resolveFoundations();

        if ($this->option('include-bundles')) {
            $this->loadProductNames($file);
        }

        $handle = fopen($file, 'r');

        if ($handle === false) {
            $this->error("Unable to open file: {$file}");

            return self::FAILURE;
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            $this->error('CSV appears to be empty.');
            fclose($handle);

            return self::FAILURE;
        }

        $map = $this->buildColumnMap($header);

        foreach (['Type', 'SKU', 'Name', 'Published'] as $required) {
            if (! isset($map[$required])) {
                $this->error("CSV is missing required column: {$required}");
                fclose($handle);

                return self::FAILURE;
            }
        }

        if ($dryRun) {
            $this->warn('DRY RUN — no data will be written.');
        }

        $stats = [
            'read'              => 0,
            'imported'          => 0,
            'skipped_existing'  => 0,
            'skipped_unpublished' => 0,
            'skipped_excluded'  => 0,
            'deferred_type'     => 0,
            'skipped_no_sku'    => 0,
            'images'            => 0,
            'files'             => 0,
            'errors'            => 0,
        ];

        $deferredByType = [];
        $missingCategories = [];

        $rowIndex = -1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowIndex++;

            if ($rowIndex < $offset) {
                continue;
            }

            if ($limit > 0 && $stats['imported'] >= $limit) {
                break;
            }

            $record = $this->extractRecord($row, $map);
            $stats['read']++;

            $wooType = strtolower(trim((string) ($record['Type'] ?? '')));
            $published = trim((string) ($record['Published'] ?? ''));
            $sku = trim((string) ($record['SKU'] ?? ''));

            if ($published !== '1') {
                $stats['skipped_unpublished']++;

                continue;
            }

            $bagistoType = $this->mapType($wooType);

            // Fixed-price WC bundles → simple/virtual products (contents in description).
            if ($bagistoType === null && $this->option('include-bundles') && $this->isBundle($wooType)) {
                $bagistoType = str_contains($wooType, 'virtual') ? 'virtual' : 'simple';
            }

            if ($bagistoType === null) {
                $stats['deferred_type']++;
                $deferredByType[$wooType] = ($deferredByType[$wooType] ?? 0) + 1;

                continue;
            }

            if ($this->isExcluded($record)) {
                $stats['skipped_excluded']++;

                continue;
            }

            if ($sku === '') {
                $stats['skipped_no_sku']++;
                $this->warn("Row {$rowIndex}: skipped (no SKU) — ".($record['Name'] ?? ''));

                continue;
            }

            if (isset($this->existingSkus[strtolower($sku)])) {
                $stats['skipped_existing']++;

                continue;
            }

            $categoryIds = $this->resolveCategories($record['Categories'] ?? '', $dryRun, $missingCategories);

            if ($dryRun) {
                $stats['imported']++;
                $this->line("  [would import] <fg=cyan>{$bagistoType}</> {$sku} — ".($record['Name'] ?? ''));

                continue;
            }

            try {
                $this->importProduct($record, $bagistoType, $sku, $categoryIds, $stats);
                $this->existingSkus[strtolower($sku)] = true;
                $stats['imported']++;
                $this->line("  <fg=green>[ok]</> {$bagistoType} {$sku}");
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->error("  [error] {$sku}: ".$e->getMessage());
            }
        }

        fclose($handle);

        $this->renderReport($stats, $deferredByType, $missingCategories, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Resolve the family/channel/source/root-category IDs the importer needs.
     */
    protected function resolveFoundations(): void
    {
        $this->attributeFamilyId = DB::table('attribute_families')->where('code', 'default')->value('id')
            ?? DB::table('attribute_families')->min('id');

        $this->channelId = DB::table('channels')->min('id');

        $this->inventorySourceId = DB::table('inventory_sources')->where('code', 'default')->value('id')
            ?? DB::table('inventory_sources')->min('id')
            ?? 1;

        $this->rootCategoryId = DB::table('categories')->whereNull('parent_id')->min('id');

        $this->urlKeyAttributeId = DB::table('attributes')->where('code', 'url_key')->value('id');

        $this->existingSkus = DB::table('products')
            ->pluck('sku')
            ->filter()
            ->mapWithKeys(fn ($sku) => [strtolower(trim($sku)) => true])
            ->all();

        // Preload existing category slug => id (single-level slug map is enough for matching).
        $this->categoryBySlugPath = DB::table('category_translations')
            ->pluck('category_id', 'slug')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($this->urlKeyAttributeId) {
            $this->usedUrlKeys = DB::table('product_attribute_values')
                ->where('attribute_id', $this->urlKeyAttributeId)
                ->whereNotNull('text_value')
                ->pluck('text_value')
                ->mapWithKeys(fn ($v) => [$v => true])
                ->all();
        }
    }

    /**
     * Map a WooCommerce "Type" cell to a Bagisto product type, or null to defer/skip.
     */
    protected function mapType(string $wooType): ?string
    {
        if ($wooType === '' || str_contains($wooType, 'variation') || str_contains($wooType, 'variable')) {
            return null; // configurable — deferred (needs proper re-export)
        }

        if (str_contains($wooType, 'bundle')) {
            return null; // deferred — needs children first
        }

        if (str_contains($wooType, 'external') || str_contains($wooType, 'subscription') || str_contains($wooType, 'grouped')) {
            return null;
        }

        if (str_contains($wooType, 'downloadable')) {
            return 'downloadable';
        }

        if (str_contains($wooType, 'virtual')) {
            return 'virtual';
        }

        if (str_contains($wooType, 'simple')) {
            return 'simple';
        }

        return null;
    }

    /**
     * True for a WC Product Bundle parent (but not a variation-based one).
     */
    protected function isBundle(string $wooType): bool
    {
        return str_contains($wooType, 'bundle') && ! str_contains($wooType, 'variation');
    }

    /**
     * Preload SKU => Name from the CSV so bundle contents can be listed by name.
     */
    protected function loadProductNames(string $file): void
    {
        $handle = fopen($file, 'r');

        if ($handle === false) {
            return;
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return;
        }

        $map = $this->buildColumnMap($header);
        $skuIdx = $map['SKU'] ?? null;
        $nameIdx = $map['Name'] ?? null;

        if ($skuIdx === null || $nameIdx === null) {
            fclose($handle);

            return;
        }

        while (($row = fgetcsv($handle)) !== false) {
            $sku = trim((string) ($row[$skuIdx] ?? ''));
            $name = trim((string) ($row[$nameIdx] ?? ''));

            if ($sku !== '') {
                $this->productNames[strtolower($sku)] = $name;
            }
        }

        fclose($handle);
    }

    /**
     * Build an HTML "what's included" list from the WC bundle JSON so the fixed-price
     * bundle (imported as a simple product) still tells the customer what's inside.
     *
     * @param  array<string, string|null>  $record
     */
    protected function bundleContentsHtml(array $record): string
    {
        $raw = $this->clean($record['Bundled Items (JSON-encoded)'] ?? null);

        if ($raw === null) {
            return '';
        }

        $items = json_decode($raw, true);

        if (! is_array($items) || $items === []) {
            return '';
        }

        $lines = [];

        foreach ($items as $item) {
            $childSku = trim((string) ($item['product_id'] ?? ''));

            if ($childSku === '') {
                continue;
            }

            $qty = (int) ($item['meta_data']['quantity_default'] ?? 1);
            $qty = $qty > 0 ? $qty : 1;
            $childName = $this->productNames[strtolower($childSku)] ?? $childSku;

            $prefix = $qty > 1 ? $qty.'× ' : '';
            $lines[] = '<li>'.$prefix.e($childName).'</li>';
        }

        if ($lines === []) {
            return '';
        }

        return '<h4>What\'s included in this bundle</h4><ul>'.implode('', $lines).'</ul>';
    }

    /**
     * True when a product should be skipped (donation/NYP, ticket, subscription).
     *
     * @param  array<string, string|null>  $record
     */
    protected function isExcluded(array $record): bool
    {
        // Donations sold as pay-what-you-want (Name Your Price).
        if (strtolower(trim((string) ($record['Meta: _nyp'] ?? ''))) === 'yes') {
            return true;
        }

        /**
         * NOTE: real subscriptions are excluded by product TYPE (see mapType) — the
         * `_subscription_price` meta is NOT a reliable signal (it holds the literal
         * string "no" on ordinary products), so it is intentionally not used here.
         */

        /**
         * Event tickets are identified ONLY by category membership. The FooEvents /
         * Tribe meta columns (WooCommerceEventsType, _tribe_wooticket_for_event, …)
         * are written to nearly every product by the plugins and are NOT reliable
         * ticket signals — using them wrongly excludes shofars, books, jewelry, etc.
         */
        $categories = (string) ($record['Categories'] ?? '');

        foreach (explode(',', $categories) as $cat) {
            $top = trim(explode('>', $cat)[0]);

            if ($top !== '' && in_array($top, $this->excludedCategories, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create the product via the official repository (create + update).
     *
     * @param  array<string, string|null>  $record
     * @param  array<int, int>  $categoryIds
     * @param  array<string, int>  $stats
     */
    protected function importProduct(array $record, string $type, string $sku, array $categoryIds, array &$stats): void
    {
        /** @var ProductRepository $products */
        $products = app(ProductRepository::class);

        $product = $products->create([
            'type'                => $type,
            'attribute_family_id' => $this->attributeFamilyId,
            'sku'                 => $sku,
        ]);

        // Same event the admin fires — triggers the flat/elastic indexers.
        Event::dispatch('catalog.product.create.after', $product);

        $data = $this->buildAttributePayload($record, $type, $sku, $product->id, $categoryIds);

        if (! $this->option('skip-images')) {
            $imageFiles = $this->downloadImages($record['Images'] ?? '');

            if ($imageFiles) {
                $data['images']['files'] = $imageFiles;
                $stats['images'] += count($imageFiles);
            }
        }

        if ($type === 'downloadable' && ! $this->option('skip-files')) {
            $links = $this->buildDownloadableLinks($record, $product->id);

            if ($links) {
                $data['downloadable_links'] = $links;
                $stats['files'] += count($links);
            }
        }

        Event::dispatch('catalog.product.update.before', $product->id);

        $product = $products->update($data, $product->id);

        // Runs the flat + inventory + price + elastic indexers (as the admin does).
        Event::dispatch('catalog.product.update.after', $product);
    }

    /**
     * Build the attribute payload that Bagisto's update() expects (keyed by attribute code).
     *
     * @param  array<string, string|null>  $record
     * @param  array<int, int>  $categoryIds
     * @return array<string, mixed>
     */
    protected function buildAttributePayload(array $record, string $type, string $sku, int $productId, array $categoryIds): array
    {
        $name = $this->clean($record['Name'] ?? null) ?? $sku;

        $regular = $this->price($record['Regular price'] ?? null);
        $special = $this->price($record['Sale price'] ?? null);

        $visibility = strtolower(trim((string) ($record['Visibility in catalog'] ?? 'visible')));

        $description = $this->clean($record['Description'] ?? null) ?: '<p></p>';

        if ($this->option('include-bundles') && $this->isBundle(strtolower(trim((string) ($record['Type'] ?? ''))))) {
            $description .= $this->bundleContentsHtml($record);
        }

        $data = [
            'sku'                 => $sku,
            'url_key'             => $this->uniqueUrlKey($name, $sku),
            'name'                => $name,
            'short_description'   => $this->clean($record['Short description'] ?? null) ?: '<p></p>',
            'description'         => $description,
            'price'               => $regular ?? 0,
            'weight'              => $this->number($record['Weight (oz)'] ?? null),
            'status'              => 1,
            'visible_individually' => $visibility === 'hidden' ? 0 : 1,
            'guest_checkout'      => 1,
            'new'                 => 0,
            'featured'            => strtolower(trim((string) ($record['Is featured?'] ?? ''))) === '1' ? 1 : 0,
            'meta_title'          => $this->clean($record['Meta: _yoast_wpseo_title'] ?? null),
            'meta_description'    => $this->clean($record['Meta: _yoast_wpseo_metadesc'] ?? null),
            'channels'            => [$this->channelId],
            'categories'          => $categoryIds,
            'inventories'         => [
                $this->inventorySourceId => $this->resolveQty($record),
            ],
        ];

        if ($special !== null && $special > 0) {
            $data['special_price'] = $special;
            $data['special_price_from'] = $this->clean($record['Date sale price starts'] ?? null);
            $data['special_price_to'] = $this->clean($record['Date sale price ends'] ?? null);
        }

        return $data;
    }

    /**
     * Resolve inventory qty: use Woo tracked stock when present, otherwise the
     * configured default when the item is flagged "in stock".
     *
     * @param  array<string, string|null>  $record
     */
    protected function resolveQty(array $record): int
    {
        $stock = $this->clean($record['Stock'] ?? null);

        if ($stock !== null && is_numeric($stock)) {
            return max(0, (int) $stock);
        }

        $inStock = trim((string) ($record['In stock?'] ?? '1'));

        return $inStock === '0' ? 0 : (int) $this->option('default-qty');
    }

    /**
     * Download product images from the comma-separated Woo "Images" cell.
     *
     * @return array<int, UploadedFile>
     */
    protected function downloadImages(?string $imagesCell): array
    {
        $files = [];

        foreach ($this->splitUrls($imagesCell) as $url) {
            $uploaded = $this->downloadToUploadedFile($url);

            if ($uploaded) {
                $files[] = $uploaded;
            }
        }

        return $files;
    }

    /**
     * Build Bagisto downloadable_links payload, re-hosting each Woo file locally.
     *
     * @param  array<string, string|null>  $record
     * @return array<string, array<string, mixed>>
     */
    protected function buildDownloadableLinks(array $record, int $productId): array
    {
        $links = [];
        $limit = $this->clean($record['Download limit'] ?? null);
        $downloads = ($limit === null || (int) $limit < 0) ? 0 : (int) $limit; // 0 = unlimited

        for ($i = 1; $i <= 8; $i++) {
            $url = $this->clean($record["Download {$i} URL"] ?? null);

            if ($url === null) {
                continue;
            }

            $title = $this->clean($record["Download {$i} name"] ?? null) ?? "Download {$i}";

            $entry = [
                'title'      => $title,
                'price'      => 0,
                'downloads'  => $downloads,
                'sort_order' => $i,
                'url'        => null,
            ];

            $stored = $this->option('skip-files') ? null : $this->storePrivateFile($url, $productId);

            if ($stored) {
                $entry['type'] = 'file';
                $entry['file'] = $stored['path'];
                $entry['file_name'] = $stored['name'];
            } else {
                // Fallback: keep the original URL (only viable while the old site is up).
                $entry['type'] = 'url';
                $entry['url'] = $url;
            }

            $links['link_'.$i] = $entry;
        }

        return $links;
    }

    /**
     * Fetch a remote URL's body. Uses a browser User-Agent because the Woo media
     * host sits behind a WAF/Cloudflare that returns 403 to header-less requests
     * (PHP's default file_get_contents). Returns null (and warns) on failure.
     */
    protected function fetch(string $url): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; CLM-Migration/1.0; +https://curtlandry.com)',
                'Accept'     => '*/*',
            ])->timeout(60)->retry(2, 500)->get($url);

            if ($response->failed()) {
                $this->warn("    download failed HTTP {$response->status()}: {$url}");

                return null;
            }

            $body = $response->body();

            return $body === '' ? null : $body;
        } catch (\Throwable $e) {
            $this->warn("    download error: {$url} ({$e->getMessage()})");

            return null;
        }
    }

    /**
     * Download a remote URL and wrap it as an UploadedFile for Bagisto's image pipeline.
     */
    protected function downloadToUploadedFile(string $url): ?UploadedFile
    {
        $contents = $this->fetch($url);

        if ($contents === null) {
            return null;
        }

        $name = basename(parse_url($url, PHP_URL_PATH) ?: 'image.jpg');
        $tmp = tempnam(sys_get_temp_dir(), 'woo_img_');
        file_put_contents($tmp, $contents);

        return new UploadedFile($tmp, $name, null, null, true);
    }

    /**
     * Download a remote file into Bagisto's private disk for downloadable links.
     *
     * Streams to disk (constant memory — never buffers the body) and refuses to
     * re-host files that are either (a) on a persistent host such as S3, or
     * (b) larger than --max-file-mb. In those cases it returns null so the caller
     * keeps the original URL as a `url`-type link instead. This is what prevents
     * OOM on the multi-GB teaching MP4s that already live on S3.
     *
     * @return array{path: string, name: string}|null
     */
    protected function storePrivateFile(string $url, int $productId): ?array
    {
        $maxBytes = max(1, (int) $this->option('max-file-mb')) * 1024 * 1024;

        // Files already on a persistent host (S3) stay as URL links — no re-host.
        if ($this->isPersistentHost($url) && ! $this->option('rehost-remote')) {
            $this->line("    keeping as URL link (persistent host): {$url}");

            return null;
        }

        // Skip re-hosting oversized files up front (avoids pulling multi-GB blobs).
        $size = $this->remoteSize($url);

        if ($size !== null && $size > $maxBytes) {
            $this->warn('    keeping as URL link ('.round($size / 1048576).' MB > '.$this->option('max-file-mb').' MB cap): '.$url);

            return null;
        }

        $name = basename(parse_url($url, PHP_URL_PATH) ?: 'file');
        $tmp = tempnam(sys_get_temp_dir(), 'woo_dl_');

        if (! $this->streamDownload($url, $tmp)) {
            @unlink($tmp);

            return null;
        }

        // Guard for hosts that sent no Content-Length: drop if it blew the cap.
        if (filesize($tmp) > $maxBytes) {
            $this->warn('    keeping as URL link (downloaded '.round(filesize($tmp) / 1048576).' MB > cap): '.$url);
            @unlink($tmp);

            return null;
        }

        $path = Storage::disk('private')->putFileAs(
            'product_downloadable_links/'.$productId,
            new File($tmp),
            Str::random(40).'-'.$name
        );

        @unlink($tmp);

        return ['path' => $path, 'name' => $name];
    }

    /**
     * True if the file is hosted somewhere that survives the WordPress
     * decommission (e.g. S3) — such files are referenced by URL, not re-hosted.
     */
    protected function isPersistentHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_contains($host, 'amazonaws.com')
            || str_contains($host, 'cloudfront.net')
            || str_contains($host, 'digitaloceanspaces.com');
    }

    /**
     * Best-effort remote size via HEAD (Content-Length). Null if unknown.
     */
    protected function remoteSize(string $url): ?int
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; CLM-Migration/1.0; +https://curtlandry.com)',
                'Accept'     => '*/*',
            ])->timeout(30)->head($url);

            if (! $response->successful()) {
                return null;
            }

            $len = $response->header('Content-Length');

            return ($len === null || $len === '') ? null : (int) $len;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Stream a remote URL straight to a local path without buffering the body in
     * memory (Guzzle `sink`). Returns true on a successful (2xx) download.
     */
    protected function streamDownload(string $url, string $path): bool
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; CLM-Migration/1.0; +https://curtlandry.com)',
                'Accept'     => '*/*',
            ])->withOptions(['sink' => $path])->timeout(300)->get($url);

            if (! $response->successful()) {
                $this->warn("    download failed HTTP {$response->status()}: {$url}");

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->warn("    download error: {$url} ({$e->getMessage()})");

            return false;
        }
    }

    /**
     * Ensure each Woo category path exists in Bagisto; return the matched IDs.
     *
     * @param  array<int, string>  $missingCategories
     * @return array<int, int>
     */
    protected function resolveCategories(string $categoriesCell, bool $dryRun, array &$missingCategories): array
    {
        $ids = [];

        foreach (explode(',', $categoriesCell) as $path) {
            $path = trim($path);

            if ($path === '') {
                continue;
            }

            $id = $this->ensureCategoryPath($path, $dryRun, $missingCategories);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Walk/create a hierarchical "Parent > Child" category path.
     *
     * @param  array<int, string>  $missingCategories
     */
    protected function ensureCategoryPath(string $path, bool $dryRun, array &$missingCategories): ?int
    {
        $segments = array_filter(array_map('trim', explode('>', $path)));
        $parentId = $this->rootCategoryId;
        $lastId = null;

        foreach ($segments as $segment) {
            $slug = Str::slug($segment);

            if (isset($this->categoryBySlugPath[$slug])) {
                $lastId = $this->categoryBySlugPath[$slug];
                $parentId = $lastId;

                continue;
            }

            if ($dryRun) {
                $missingCategories[$slug] = $segment;
                $lastId = null;

                continue;
            }

            /** @var CategoryRepository $categories */
            $categories = app(CategoryRepository::class);

            $category = $categories->create([
                'locale'       => 'all',
                'name'         => $segment,
                'slug'         => $slug,
                'parent_id'    => $parentId,
                'status'       => 1,
                'position'     => 1,
                'display_mode' => 'products_and_description',
                'description'  => '<p></p>',
            ]);

            $this->categoryBySlugPath[$slug] = $category->id;
            $lastId = $category->id;
            $parentId = $category->id;
        }

        return $lastId;
    }

    /**
     * Produce a unique url_key from the product name (falling back to SKU).
     */
    protected function uniqueUrlKey(string $name, string $sku): string
    {
        $base = Str::slug($name) ?: Str::slug($sku);

        if ($base === '') {
            $base = 'product';
        }

        $candidate = $base;
        $i = 1;

        while (isset($this->usedUrlKeys[$candidate])) {
            $candidate = $base.'-'.Str::slug($sku);

            if (isset($this->usedUrlKeys[$candidate])) {
                $candidate = $base.'-'.$sku.'-'.$i++;
            }
        }

        $this->usedUrlKeys[$candidate] = true;

        return $candidate;
    }

    /**
     * Split a comma/space separated list of URLs into a clean array.
     *
     * @return array<int, string>
     */
    protected function splitUrls(?string $cell): array
    {
        if ($cell === null || trim($cell) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $cell)), function ($u) {
            return $u !== '' && filter_var($u, FILTER_VALIDATE_URL);
        }));
    }

    /**
     * Map CSV header names to their column index.
     *
     * @param  array<int, string>  $header
     * @return array<string, int>
     */
    protected function buildColumnMap(array $header): array
    {
        $map = [];

        foreach ($header as $index => $name) {
            $key = trim((string) $name);

            if ($key !== '') {
                $map[$key] = $index;
            }
        }

        return $map;
    }

    /**
     * Turn a raw CSV row into a keyed record based on the column map.
     *
     * @param  array<int, string|null>  $row
     * @param  array<string, int>  $map
     * @return array<string, string|null>
     */
    protected function extractRecord(array $row, array $map): array
    {
        $record = [];

        foreach ($map as $key => $index) {
            $value = $row[$index] ?? null;
            $record[$key] = is_string($value) ? trim($value) : $value;
        }

        return $record;
    }

    /**
     * Normalise a value: trim and convert empty strings to null.
     */
    protected function clean($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Parse a price cell into a float, or null when empty/invalid.
     */
    protected function price($value): ?float
    {
        $value = $this->clean($value);

        if ($value === null) {
            return null;
        }

        $value = preg_replace('/[^0-9.\-]/', '', $value);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Parse a numeric cell into a string number, or null.
     */
    protected function number($value): ?string
    {
        $value = $this->clean($value);

        return ($value !== null && is_numeric($value)) ? $value : null;
    }

    /**
     * Print the final summary report.
     *
     * @param  array<string, int>  $stats
     * @param  array<string, int>  $deferredByType
     * @param  array<string, string>  $missingCategories
     */
    protected function renderReport(array $stats, array $deferredByType, array $missingCategories, bool $dryRun): void
    {
        $this->newLine();
        $this->info($dryRun ? 'Dry run complete.' : 'Import complete.');

        $this->table(
            ['Metric', 'Count'],
            [
                ['Rows read', $stats['read']],
                ['Imported', $stats['imported']],
                ['Skipped — already exists', $stats['skipped_existing']],
                ['Skipped — unpublished', $stats['skipped_unpublished']],
                ['Skipped — excluded (donation/ticket/sub)', $stats['skipped_excluded']],
                ['Skipped — no SKU', $stats['skipped_no_sku']],
                ['Deferred — unsupported type', $stats['deferred_type']],
                ['Images attached', $stats['images']],
                ['Downloadable files attached', $stats['files']],
                ['Errors', $stats['errors']],
            ]
        );

        if ($deferredByType) {
            $this->newLine();
            $this->warn('Deferred product types (build separately — see runbook §21.2):');
            foreach ($deferredByType as $type => $count) {
                $this->line(sprintf('  %-32s %d', $type === '' ? '(blank)' : $type, $count));
            }
        }

        if ($dryRun && $missingCategories) {
            $this->newLine();
            $this->warn('Categories that would be created (run without --dry-run):');
            foreach ($missingCategories as $slug => $name) {
                $this->line("  {$name}  ({$slug})");
            }
        }
    }
}
