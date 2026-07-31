<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Finish the catalog: create the handful of WooCommerce variable (configurable)
 * products that the CSV importer deferred, plus the one broken "variable" product
 * that has no variation data (imported as a disabled simple).
 *
 * Why a command and not the admin UI: Bagisto's configurable create screen needs
 * hundreds of interactions per product; this does the SAME thing through the
 * official ProductRepository / Configurable type (super_attributes → auto variants
 * → per-variant SKU/price/qty), fires the same index events the admin fires, and
 * downloads the product images. Not a band-aid — it's the admin's own code path.
 *
 * Targets (published, non-donation) read from the re-export CSV:
 *   - CLM2026x2653  Shabbat Box   → new attribute "Candle Holder Finish"
 *   - CLM2024x0005  MAPA Hat      → existing "Color" (variations get generated SKUs)
 *   - "Pressing Pause…"           → SIMPLE, disabled (no SKU/price/image in source)
 *
 * Idempotent: skips a product whose SKU already exists.
 */
class FinishConfigurables extends Command
{
    protected $signature = 'catalog:finish-configurables
        {file : Absolute path to the WooCommerce product CSV re-export}
        {--dry-run : Report what would be created without writing}';

    protected $description = 'Create the deferred configurable products (+ Pressing Pause simple) via the official repository';

    protected ?int $familyId = null;

    protected ?int $channelId = null;

    protected string $channelCode = 'default';

    protected string $localeCode = 'en';

    protected ?int $inventorySourceId = null;

    protected ?int $rootCategoryId = null;

    /** @var array<string,int> slug => category_id */
    protected array $categoryBySlug = [];

    /** @var array<string,true> */
    protected array $existingSkus = [];

    /** @var array<int,array<string,string>> */
    protected array $rows = [];

    /** @var array<string,int> header name => index */
    protected array $col = [];

    public function handle(): int
    {
        $file = $this->argument('file');

        if (! is_file($file) || ! is_readable($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $this->resolveFoundations();
        $this->loadCsv($file);

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no data will be written.');
        }

        // 1) Shabbat Box — new "Candle Holder Finish" attribute.
        $this->buildConfigurable('CLM2026x2653', 'Candle Holder Finish', $dryRun);

        // 2) MAPA Hat — existing "Color" attribute; variations get generated SKUs.
        $this->buildConfigurable('CLM2024x0005', 'Color', $dryRun);

        // 3) Pressing Pause — simple, disabled (no usable variation data in Woo).
        $this->buildPressingPause($dryRun);

        $this->info($dryRun ? 'Dry run complete.' : 'Done.');

        return self::SUCCESS;
    }

    protected function resolveFoundations(): void
    {
        $this->familyId = DB::table('attribute_families')->where('code', 'default')->value('id')
            ?? DB::table('attribute_families')->min('id');

        $channel = DB::table('channels')->orderBy('id')->first();
        $this->channelId = (int) $channel->id;
        $this->channelCode = $channel->code;

        $this->localeCode = DB::table('locales')->value('code') ?? 'en';

        $this->inventorySourceId = DB::table('inventory_sources')->where('code', 'default')->value('id')
            ?? DB::table('inventory_sources')->min('id') ?? 1;

        $this->rootCategoryId = DB::table('categories')->whereNull('parent_id')->min('id');

        $this->categoryBySlug = DB::table('category_translations')
            ->pluck('category_id', 'slug')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->existingSkus = DB::table('products')->pluck('sku')
            ->filter()
            ->mapWithKeys(fn ($s) => [strtolower(trim($s)) => true])
            ->all();
    }

    protected function loadCsv(string $file): void
    {
        $handle = fopen($file, 'r');
        $header = fgetcsv($handle);
        $this->col = array_flip(array_map('trim', $header));

        while (($row = fgetcsv($handle)) !== false) {
            $this->rows[] = $row;
        }

        fclose($handle);
    }

    protected function cell(array $row, string $name): string
    {
        return isset($this->col[$name]) ? trim((string) ($row[$this->col[$name]] ?? '')) : '';
    }

    protected function findBySku(string $sku): ?array
    {
        foreach ($this->rows as $row) {
            if ($this->cell($row, 'SKU') === $sku) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Build one configurable product from its parent SKU + variation rows.
     */
    protected function buildConfigurable(string $parentSku, string $attrName, bool $dryRun): void
    {
        if (isset($this->existingSkus[strtolower($parentSku)])) {
            $this->line("  <fg=yellow>[skip]</> {$parentSku} already exists.");

            return;
        }

        $parent = $this->findBySku($parentSku);

        if (! $parent) {
            $this->error("  {$parentSku}: not found in CSV.");

            return;
        }

        // Collect variation rows for this parent (Parent cell contains the parent SKU).
        $variations = [];

        foreach ($this->rows as $row) {
            if (str_contains($this->cell($row, 'Type'), 'variation')
                && str_contains($this->cell($row, 'Parent'), $parentSku)) {
                $label = $this->cell($row, 'Attribute 1 value(s)');

                if ($label === '') {
                    continue;
                }

                $sku = $this->cell($row, 'SKU');

                if ($sku === '') {
                    $sku = $parentSku.'-'.strtoupper(Str::slug($label));
                }

                $variations[] = [
                    'label' => $label,
                    'sku'   => $sku,
                    'price' => (float) $this->cell($row, 'Regular price'),
                    'qty'   => $this->resolveQty($row),
                    'image' => $this->firstImage($row),
                ];
            }
        }

        if ($variations === []) {
            $this->error("  {$parentSku}: no variations found.");

            return;
        }

        $name = $this->cell($parent, 'Name') ?: $parentSku;
        $labels = array_values(array_unique(array_column($variations, 'label')));

        $this->line("  <fg=cyan>{$parentSku}</> \"{$name}\" — attr \"{$attrName}\", ".count($variations).' variants: '
            .implode(', ', array_map(fn ($v) => "{$v['label']} ({$v['sku']} @ \${$v['price']})", $variations)));

        if ($dryRun) {
            return;
        }

        [$attrCode, $attrId, $labelToOption] = $this->ensureAttribute($attrName, $labels);

        $optionIds = [];

        foreach ($variations as $v) {
            $oid = $labelToOption[strtolower($v['label'])] ?? null;

            if ($oid === null) {
                $this->error("    option not found for '{$v['label']}' — skipping variant.");

                continue;
            }

            $v['option_id'] = $oid;
            $optionIds[$oid] = $v;
        }

        /** @var ProductRepository $products */
        $products = app(ProductRepository::class);

        $product = $products->create([
            'type'                => 'configurable',
            'attribute_family_id' => $this->familyId,
            'sku'                 => $parentSku,
            'super_attributes'    => [$attrCode => array_keys($optionIds)],
        ]);

        Event::dispatch('catalog.product.create.after', $product);

        $product->load('variants');

        $weight = $this->cell($parent, 'Weight (oz)') ?: '0';
        $categoryIds = $this->resolveCategories($this->cell($parent, 'Categories'));

        // Map each auto-generated variant to its target row via its option id.
        $variantData = [];

        foreach ($product->variants as $variant) {
            $oid = (int) DB::table('product_attribute_values')
                ->where('product_id', $variant->id)
                ->where('attribute_id', $attrId)
                ->value('integer_value');

            $target = $optionIds[$oid] ?? null;

            if (! $target) {
                continue;
            }

            $variantData[$variant->id] = [
                'sku'               => $target['sku'],
                'name'              => $name.' - '.$target['label'],
                'price'             => $target['price'],
                'weight'            => $weight,
                'status'            => 1,
                'url_key'           => Str::slug($target['sku']),
                'short_description' => $name,
                'description'       => $name,
                $attrCode           => (string) $oid,
                'inventories'       => [$this->inventorySourceId => $target['qty']],
            ];
        }

        $updateData = [
            'sku'                  => $parentSku,
            'url_key'              => $this->uniqueUrlKey($name, $parentSku),
            'name'                 => $name,
            'short_description'    => $this->cellHtml($parent, 'Short description') ?: '<p></p>',
            'description'          => $this->cellHtml($parent, 'Description') ?: '<p></p>',
            'weight'               => $weight,
            'status'               => 1,
            'visible_individually' => 1,
            'guest_checkout'       => 1,
            'channels'             => [$this->channelId],
            'categories'           => $categoryIds,
            'channel'              => $this->channelCode,
            'locale'               => $this->localeCode,
            'variants'             => $variantData,
        ];

        $images = $this->downloadImages($this->cell($parent, 'Images'));

        if ($images) {
            $updateData['images']['files'] = $images;
        }

        Event::dispatch('catalog.product.update.before', $product->id);
        $product = $products->update($updateData, $product->id);
        Event::dispatch('catalog.product.update.after', $product);

        $this->existingSkus[strtolower($parentSku)] = true;
        $this->info("    [ok] {$parentSku} configurable with ".count($variantData).' variants ('.count($images).' parent images).');
    }

    /**
     * Ensure a configurable Select attribute exists; return [code, id, label=>optionId].
     *
     * @param  array<int,string>  $labels
     * @return array{0:string,1:int,2:array<string,int>}
     */
    protected function ensureAttribute(string $name, array $labels): array
    {
        $code = Str::slug($name, '_');

        $attribute = DB::table('attributes')->where('code', $code)->first();

        if (! $attribute) {
            $options = [];

            foreach ($labels as $i => $label) {
                $options[] = [
                    'admin_name' => $label,
                    'sort_order' => $i + 1,
                    $this->localeCode => ['label' => $label],
                ];
            }

            $created = app(AttributeRepository::class)->create([
                'code'              => $code,
                'admin_name'        => $name,
                'type'              => 'select',
                'is_configurable'   => 1,
                'is_required'       => 0,
                'is_unique'         => 0,
                'is_filterable'     => 0,
                'value_per_locale'  => 0,
                'value_per_channel' => 0,
                'position'          => (int) DB::table('attributes')->max('id') + 1,
                'options'           => $options,
            ]);

            $attrId = $created->id;
            $this->info("    created attribute '{$code}' with ".count($labels).' options.');
        } else {
            $attrId = (int) $attribute->id;
        }

        // Defensive: ensure every option has a translation for our locale.
        $this->ensureOptionTranslations($attrId);

        $map = [];

        $optRows = DB::table('attribute_options as ao')
            ->join('attribute_option_translations as aot', 'aot.attribute_option_id', '=', 'ao.id')
            ->where('ao.attribute_id', $attrId)
            ->get(['ao.id', 'aot.label']);

        foreach ($optRows as $o) {
            $map[strtolower(trim($o->label))] = (int) $o->id;
        }

        return [$code, $attrId, $map];
    }

    protected function ensureOptionTranslations(int $attrId): void
    {
        $options = DB::table('attribute_options')->where('attribute_id', $attrId)->get(['id', 'admin_name']);

        foreach ($options as $o) {
            $exists = DB::table('attribute_option_translations')
                ->where('attribute_option_id', $o->id)
                ->where('locale', $this->localeCode)
                ->exists();

            if (! $exists) {
                DB::table('attribute_option_translations')->insert([
                    'attribute_option_id' => $o->id,
                    'locale'              => $this->localeCode,
                    'label'               => $o->admin_name,
                ]);
            }
        }
    }

    protected function buildPressingPause(bool $dryRun): void
    {
        $sku = 'CLM-PRESSING-PAUSE';

        if (isset($this->existingSkus[strtolower($sku)])) {
            $this->line("  <fg=yellow>[skip]</> {$sku} already exists.");

            return;
        }

        $row = null;

        foreach ($this->rows as $r) {
            if (str_starts_with($this->cell($r, 'Name'), 'Pressing Pause') && $this->cell($r, 'Type') === 'variable') {
                $row = $r;
                break;
            }
        }

        if (! $row) {
            $this->error('  Pressing Pause: not found in CSV.');

            return;
        }

        $name = $this->cell($row, 'Name');
        $this->line("  <fg=cyan>{$sku}</> \"{$name}\" — SIMPLE, disabled, price 16.99 (placeholder; set price + image + enable in admin).");

        if ($dryRun) {
            return;
        }

        /** @var ProductRepository $products */
        $products = app(ProductRepository::class);

        $product = $products->create([
            'type'                => 'simple',
            'attribute_family_id' => $this->familyId,
            'sku'                 => $sku,
        ]);

        Event::dispatch('catalog.product.create.after', $product);

        $updateData = [
            'sku'                  => $sku,
            'url_key'              => $this->uniqueUrlKey($name, $sku),
            'name'                 => $name,
            'short_description'    => $this->cellHtml($row, 'Short description') ?: '<p></p>',
            'description'          => $this->cellHtml($row, 'Description') ?: '<p></p>',
            'weight'               => $this->cell($row, 'Weight (oz)') ?: '0',
            'price'                => 16.99,
            'status'               => 0,
            'visible_individually' => 1,
            'guest_checkout'       => 1,
            'channels'             => [$this->channelId],
            'categories'           => $this->resolveCategories($this->cell($row, 'Categories')),
            'inventories'          => [$this->inventorySourceId => 0],
        ];

        Event::dispatch('catalog.product.update.before', $product->id);
        $product = $products->update($updateData, $product->id);
        Event::dispatch('catalog.product.update.after', $product);

        $this->existingSkus[strtolower($sku)] = true;
        $this->info("    [ok] {$sku} simple (disabled).");
    }

    protected function resolveQty(array $row): int
    {
        $stock = $this->cell($row, 'Stock');

        if ($stock !== '' && is_numeric($stock)) {
            return max(0, (int) $stock);
        }

        return $this->cell($row, 'In stock?') === '0' ? 0 : 10;
    }

    protected function firstImage(array $row): ?string
    {
        foreach (explode(',', $this->cell($row, 'Images')) as $u) {
            $u = trim($u);

            if ($u !== '' && filter_var($u, FILTER_VALIDATE_URL)) {
                return $u;
            }
        }

        return null;
    }

    /**
     * @return array<int,int>
     */
    protected function resolveCategories(string $cell): array
    {
        $ids = [];

        foreach (explode(',', $cell) as $path) {
            foreach (explode('>', $path) as $seg) {
                $slug = Str::slug(trim($seg));

                if ($slug !== '' && isset($this->categoryBySlug[$slug])) {
                    $ids[] = $this->categoryBySlug[$slug];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    protected function cellHtml(array $row, string $name): string
    {
        return $this->cell($row, $name);
    }

    protected function uniqueUrlKey(string $name, string $sku): string
    {
        $base = Str::slug($name) ?: Str::slug($sku);
        $candidate = $base;

        if (DB::table('product_attribute_values')->where('text_value', $candidate)->exists()) {
            $candidate = $base.'-'.Str::slug($sku);
        }

        return $candidate;
    }

    /**
     * @return array<int,UploadedFile>
     */
    protected function downloadImages(string $cell): array
    {
        $files = [];

        foreach (explode(',', $cell) as $url) {
            $url = trim($url);

            if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            try {
                $res = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; CLM-Migration/1.0)'])
                    ->timeout(60)->retry(2, 500)->get($url);

                if ($res->failed() || $res->body() === '') {
                    continue;
                }

                $tmp = tempnam(sys_get_temp_dir(), 'cfg_img_');
                file_put_contents($tmp, $res->body());
                $files[] = new UploadedFile($tmp, basename(parse_url($url, PHP_URL_PATH) ?: 'image.jpg'), null, null, true);
            } catch (\Throwable $e) {
                $this->warn("    image download failed: {$url}");
            }
        }

        return $files;
    }
}
