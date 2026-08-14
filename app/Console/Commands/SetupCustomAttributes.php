<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Attribute\Repositories\AttributeOptionRepository;
use Webkul\Attribute\Repositories\AttributeRepository;

/**
 * Create the custom product attributes required by the 2026 feature list
 * (docs: feature-gap analysis / runbook §25):
 *
 *   - product_tags                  multiselect, filterable   → General group
 *   - seo_noindex                   boolean                   → Meta Description group
 *   - launch_at                     datetime (visible from)   → Settings group
 *   - private_link_only             boolean (hidden, by link) → Settings group
 *   - purchase_limit_per_customer   numeric text              → Settings group
 *
 * Uses the official AttributeRepository (same code path as the admin) and maps
 * each attribute into an attribute group of the default family so it shows on
 * the product edit form. Idempotent: existing attributes are skipped, but new
 * tag options from --tags-from are appended.
 *
 * The storefront/API enforcement of seo_noindex, launch_at and
 * private_link_only lives in the Next.js storefront + API layer.
 */
class SetupCustomAttributes extends Command
{
    protected $signature = 'catalog:setup-custom-attributes
        {--tags-from= : Optional WooCommerce product CSV — seeds product_tags options from its "Tags" column}
        {--dry-run : Report what would be created without writing}';

    protected $description = 'Create custom attributes (tags, noindex, launch date, private flag, purchase limit) and attach them to the product form';

    protected string $localeCode = 'en';

    protected ?int $familyId = null;

    public function handle(): int
    {
        $this->localeCode = DB::table('locales')->value('code') ?? 'en';

        $this->familyId = DB::table('attribute_families')->where('code', 'default')->value('id')
            ?? DB::table('attribute_families')->min('id');

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no data will be written.');
        }

        $tagOptions = $this->collectTags();

        $definitions = [
            [
                'code'       => 'product_tags',
                'admin_name' => 'Product Tags',
                'type'       => 'multiselect',
                'group'      => 'General',
                'extra'      => ['is_filterable' => 1],
                'options'    => $tagOptions,
            ],
            [
                'code'       => 'seo_noindex',
                'admin_name' => 'SEO: Exclude from Search Engines (noindex)',
                'type'       => 'boolean',
                'group'      => 'Meta Description',
            ],
            [
                'code'       => 'launch_at',
                'admin_name' => 'Scheduled Launch (visible from)',
                'type'       => 'datetime',
                'group'      => 'Settings',
            ],
            [
                'code'       => 'private_link_only',
                'admin_name' => 'Private (hidden from catalog, accessible by link)',
                'type'       => 'boolean',
                'group'      => 'Settings',
            ],
            [
                'code'       => 'purchase_limit_per_customer',
                'admin_name' => 'Purchase Limit per Customer (blank = unlimited)',
                'type'       => 'text',
                'group'      => 'Settings',
                'extra'      => ['validation' => 'numeric'],
            ],
        ];

        foreach ($definitions as $definition) {
            $this->ensureAttribute($definition, $dryRun);
        }

        $this->info($dryRun ? 'Dry run complete.' : 'Done.');

        return self::SUCCESS;
    }

    /**
     * @param array{code:string,admin_name:string,type:string,group:string,extra?:array,options?:array<int,string>} $definition
     */
    protected function ensureAttribute(array $definition, bool $dryRun): void
    {
        $existing = DB::table('attributes')->where('code', $definition['code'])->first();

        if ($existing) {
            $this->line("  <fg=yellow>[exists]</> {$definition['code']}");
            $this->appendMissingOptions((int) $existing->id, $definition['options'] ?? [], $dryRun);
            $this->attachToGroup((int) $existing->id, $definition['group'], $dryRun);

            return;
        }

        $optionCount = count($definition['options'] ?? []);
        $this->line("  <fg=cyan>[create]</> {$definition['code']} ({$definition['type']}) → group \"{$definition['group']}\""
            .($optionCount ? " with {$optionCount} options" : ''));

        if ($dryRun) {
            return;
        }

        $options = [];

        foreach ($definition['options'] ?? [] as $i => $label) {
            $options[] = [
                'admin_name'      => $label,
                'sort_order'      => $i + 1,
                $this->localeCode => ['label' => $label],
            ];
        }

        $attribute = app(AttributeRepository::class)->create(array_merge([
            'code'              => $definition['code'],
            'admin_name'        => $definition['admin_name'],
            'type'              => $definition['type'],
            'is_required'       => 0,
            'is_unique'         => 0,
            'is_filterable'     => 0,
            'is_configurable'   => 0,
            'is_user_defined'   => 1,
            'value_per_locale'  => 0,
            'value_per_channel' => 0,
            'position'          => (int) DB::table('attributes')->max('position') + 1,
            'options'           => $options,
            $this->localeCode   => ['name' => $definition['admin_name']],
        ], $definition['extra'] ?? []));

        $this->attachToGroup((int) $attribute->id, $definition['group'], false);
        $this->info("    [ok] {$definition['code']} created.");
    }

    /**
     * Attach the attribute to the named group of the default family so it
     * renders on the product edit form. No-op if already mapped.
     */
    protected function attachToGroup(int $attributeId, string $groupName, bool $dryRun): void
    {
        $group = DB::table('attribute_groups')
            ->where('attribute_family_id', $this->familyId)
            ->where('name', $groupName)
            ->first();

        if (! $group) {
            $this->error("    group \"{$groupName}\" not found in default family — attach manually in Admin → Catalog → Attribute Families.");

            return;
        }

        $mapped = DB::table('attribute_group_mappings')
            ->where('attribute_id', $attributeId)
            ->where('attribute_group_id', $group->id)
            ->exists();

        if ($mapped || $dryRun) {
            return;
        }

        $position = (int) DB::table('attribute_group_mappings')
            ->where('attribute_group_id', $group->id)
            ->max('position') + 1;

        DB::table('attribute_group_mappings')->insert([
            'attribute_id'       => $attributeId,
            'attribute_group_id' => $group->id,
            'position'           => $position,
        ]);
    }

    /**
     * For an existing multiselect (product_tags), append any tag options that
     * don't exist yet — keeps re-runs with a fresh CSV additive and safe.
     *
     * @param array<int,string> $labels
     */
    protected function appendMissingOptions(int $attributeId, array $labels, bool $dryRun): void
    {
        if ($labels === []) {
            return;
        }

        $existing = DB::table('attribute_options')
            ->where('attribute_id', $attributeId)
            ->pluck('admin_name')
            ->map(fn ($n) => strtolower(trim((string) $n)))
            ->flip()
            ->all();

        $missing = array_values(array_filter($labels, fn ($l) => ! isset($existing[strtolower($l)])));

        if ($missing === []) {
            return;
        }

        $this->line('    '.count($missing).' new tag options: '.implode(', ', array_slice($missing, 0, 10)).(count($missing) > 10 ? ', …' : ''));

        if ($dryRun) {
            return;
        }

        $sortOrder = (int) DB::table('attribute_options')->where('attribute_id', $attributeId)->max('sort_order');

        foreach ($missing as $label) {
            app(AttributeOptionRepository::class)->create([
                'attribute_id'    => $attributeId,
                'admin_name'      => $label,
                'sort_order'      => ++$sortOrder,
                $this->localeCode => ['label' => $label],
            ]);
        }
    }

    /**
     * Read distinct tags from the Woo product CSV "Tags" column.
     *
     * @return array<int,string>
     */
    protected function collectTags(): array
    {
        $file = $this->option('tags-from');

        if (! $file) {
            return [];
        }

        if (! is_file($file) || ! is_readable($file)) {
            $this->error("Tags CSV not found: {$file}");

            return [];
        }

        $handle = fopen($file, 'r');
        $header = array_map('trim', fgetcsv($handle) ?: []);
        $col = array_flip($header);

        if (! isset($col['Tags'])) {
            $this->error('CSV has no "Tags" column.');
            fclose($handle);

            return [];
        }

        $tags = [];

        while (($row = fgetcsv($handle)) !== false) {
            foreach (explode(',', (string) ($row[$col['Tags']] ?? '')) as $tag) {
                $tag = trim($tag);

                if ($tag !== '' && ! Str::startsWith($tag, '"')) {
                    $tags[Str::lower($tag)] = $tag;
                }
            }
        }

        fclose($handle);

        $list = array_values($tags);
        natcasesort($list);

        $this->line('  Found '.count($list).' distinct tags in CSV.');

        return array_values($list);
    }
}
