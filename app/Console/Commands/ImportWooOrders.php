<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Import WooCommerce order history into Bagisto from two CSV exports produced
 * straight from the (HPOS) Woo database:
 *
 *   1. orders  → wp_wc_orders (+ wp_wc_order_addresses)         [--orders]
 *   2. items   → wp_woocommerce_order_items(+itemmeta, _sku)    [--items]
 *
 * Design (no band-aid): this is a historical back-fill, so it writes the sales
 * tables directly inside a per-order transaction instead of going through
 * OrderRepository::create() — that method is the LIVE checkout path and would
 * renumber orders, DECREMENT stock, and fire invoice/e-mail events. We instead
 * reproduce exactly the rows Bagisto's own checkout leaves behind:
 *
 *   orders · order_items · addresses(order_billing/order_shipping) ·
 *   order_payment · downloadable_link_purchased (restores digital access)
 *
 * SCOPE (decided in DEPLOYMENT_RUNBOOK §21.4): only orders that contain at
 * least one product present in the migrated Bagisto catalog are imported —
 * everything else in Woo is donations / legacy subscriptions / no-SKU fees that
 * are being decommissioned (GiveWP + Stripe). Failed orders are excluded by
 * default (never paid); pass --include-failed to keep them.
 *
 * Join keys:  order → customer by EMAIL   ·   line → product by SKU
 * Unmatched lines are kept as plain-text history so totals still reconcile.
 *
 * Idempotent by increment_id (the original Woo order number is preserved), so
 * the command is safe to re-run — e.g. after configurable/bundle SKUs land, the
 * re-run back-links those lines without duplicating orders.
 */
class ImportWooOrders extends Command
{
    protected $signature = 'woo:import-orders
        {--orders= : Absolute path to the orders CSV (wp_wc_orders export)}
        {--items= : Absolute path to the line-items CSV (order items export)}
        {--include-failed : Also import Woo "failed" orders (default: skip)}
        {--skip-downloads : Do not create downloadable_link_purchased rows}
        {--limit=0 : Import at most this many orders (0 = no limit)}
        {--offset=0 : Skip this many in-scope orders before importing}
        {--dry-run : Analyse and report without writing anything}';

    protected $description = 'Import WooCommerce order history (orders, items, addresses, payment, digital access) from CSV';

    protected string $customerType = \Webkul\Customer\Models\Customer::class;

    protected string $channelType = \Webkul\Core\Models\Channel::class;

    protected int $channelId = 1;

    protected string $channelName = 'Default';

    /** @var array<string,array{id:int,first_name:?string,last_name:?string}> email(lower) => customer */
    protected array $customerByEmail = [];

    /** @var array<string,array{id:int,type:string,name:?string}> sku(lower) => product */
    protected array $productBySku = [];

    /** @var array<int,array<int,array<string,mixed>>> product_id => downloadable links */
    protected array $linksByProduct = [];

    /** @var array<string,true> increment_ids already present */
    protected array $existingOrders = [];

    /** Woo status (normalised, no wc- prefix / -a suffix) => Bagisto status. */
    protected array $statusMap = [
        'completed'  => 'completed',
        'processing' => 'processing',
        'on-hold'    => 'pending',
        'pending'    => 'pending',
        'cancelled'  => 'canceled',
        'refunded'   => 'closed',
        'failed'     => 'canceled',
    ];

    /** Statuses that count as paid (get invoiced totals + available downloads). */
    protected array $paidStatuses = ['completed', 'processing', 'closed'];

    public function handle(): int
    {
        $ordersFile = (string) $this->option('orders');
        $itemsFile = (string) $this->option('items');

        foreach (['orders' => $ordersFile, 'items' => $itemsFile] as $label => $path) {
            if ($path === '' || ! is_file($path) || ! is_readable($path)) {
                $this->error("Missing/unreadable --{$label} file: {$path}");

                return self::FAILURE;
            }
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $offset = max(0, (int) $this->option('offset'));
        $includeFailed = (bool) $this->option('include-failed');

        if ($dryRun) {
            $this->warn('DRY RUN — no data will be written.');
        }

        $this->resolveFoundations();

        $this->info('Loading line items…');
        $itemsByOrder = $this->loadItems($itemsFile);
        $this->line('  '.number_format(array_sum(array_map('count', $itemsByOrder))).' line items across '.number_format(count($itemsByOrder)).' orders.');

        $stats = array_fill_keys([
            'read', 'imported', 'skipped_existing', 'skipped_no_product',
            'skipped_failed', 'skipped_status', 'items', 'items_linked',
            'guest_orders', 'linked_orders', 'downloads', 'errors',
        ], 0);

        $handle = fopen($ordersFile, 'r');
        $header = fgetcsv($handle);
        $map = array_flip(array_map('trim', $header));

        foreach (['order_id', 'status', 'billing_email', 'total_amount', 'date_created_gmt'] as $req) {
            if (! isset($map[$req])) {
                $this->error("Orders CSV missing required column: {$req}");
                fclose($handle);

                return self::FAILURE;
            }
        }

        $inScope = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $stats['read']++;

            $get = fn (string $k) => isset($map[$k]) ? trim((string) ($row[$map[$k]] ?? '')) : '';

            $wooId = $get('order_id');
            $woo = $this->normaliseStatus($get('status'));

            if ($woo === 'auto-draft' || $woo === '') {
                $stats['skipped_status']++;

                continue;
            }

            if ($woo === 'failed' && ! $includeFailed) {
                $stats['skipped_failed']++;

                continue;
            }

            $status = $this->statusMap[$woo] ?? 'pending';

            $items = $itemsByOrder[$wooId] ?? [];

            if (! $this->hasCatalogProduct($items)) {
                $stats['skipped_no_product']++;

                continue;
            }

            if (isset($this->existingOrders[$wooId])) {
                $stats['skipped_existing']++;

                continue;
            }

            $inScope++;

            if ($inScope <= $offset) {
                continue;
            }

            if ($limit > 0 && $stats['imported'] >= $limit) {
                break;
            }

            $email = strtolower($get('billing_email'));
            $customer = $this->customerByEmail[$email] ?? null;

            if ($dryRun) {
                $stats['imported']++;
                $customer ? $stats['linked_orders']++ : $stats['guest_orders']++;

                foreach ($items as $it) {
                    $stats['items']++;
                    $sku = $it['sku'];

                    if ($sku !== null && isset($this->productBySku[strtolower($sku)])) {
                        $stats['items_linked']++;

                        if ($customer && stripos($this->productBySku[strtolower($sku)]['type'], 'downloadable') !== false) {
                            $stats['downloads'] += max(1, count($this->linksByProduct[$this->productBySku[strtolower($sku)]['id']] ?? []));
                        }
                    }
                }

                continue;
            }

            try {
                DB::transaction(function () use ($row, $get, $status, $items, $customer, &$stats) {
                    $this->importOrder($row, $get, $status, $items, $customer, $stats);
                });

                $this->existingOrders[$get('order_id')] = true;
                $stats['imported']++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->error("  [error] order {$wooId}: ".$e->getMessage());
            }
        }

        fclose($handle);

        $this->renderReport($stats, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Preload the lookup maps the importer joins against.
     */
    protected function resolveFoundations(): void
    {
        $channel = DB::table('channels')->orderBy('id')->first();

        if ($channel) {
            $this->channelId = (int) $channel->id;
            $this->channelName = DB::table('channel_translations')
                ->where('channel_id', $channel->id)
                ->value('name') ?? ucfirst($channel->code);
        }

        $this->customerByEmail = DB::table('customers')
            ->select('id', 'email', 'first_name', 'last_name')
            ->get()
            ->mapWithKeys(fn ($c) => [strtolower(trim($c->email)) => [
                'id'         => (int) $c->id,
                'first_name' => $c->first_name,
                'last_name'  => $c->last_name,
            ]])
            ->all();

        $this->productBySku = DB::table('products')
            ->select('products.id', 'products.sku', 'products.type', 'pf.name')
            ->leftJoin('product_flat as pf', function ($j) {
                $j->on('pf.product_id', '=', 'products.id');
            })
            ->whereNotNull('products.sku')
            ->get()
            ->mapWithKeys(fn ($p) => [strtolower(trim($p->sku)) => [
                'id'   => (int) $p->id,
                'type' => $p->type,
                'name' => $p->name,
            ]])
            ->all();

        // Downloadable links (+ title from translations) grouped by product.
        $titles = DB::table('product_downloadable_link_translations')
            ->pluck('title', 'product_downloadable_link_id')
            ->all();

        foreach (DB::table('product_downloadable_links')->get() as $link) {
            $this->linksByProduct[(int) $link->product_id][] = [
                'id'        => (int) $link->id,
                'title'     => $titles[$link->id] ?? null,
                'url'       => $link->url,
                'file'      => $link->file,
                'file_name' => $link->file_name,
                'type'      => $link->type,
                'downloads' => (int) $link->downloads,
            ];
        }

        $this->existingOrders = DB::table('orders')
            ->pluck('increment_id')
            ->mapWithKeys(fn ($id) => [(string) $id => true])
            ->all();
    }

    /**
     * Load the line-items CSV into memory grouped by Woo order_id.
     *
     * @return array<string,array<int,array<string,string>>>
     */
    protected function loadItems(string $file): array
    {
        $handle = fopen($file, 'r');
        $header = fgetcsv($handle);
        $map = array_flip(array_map('trim', $header));

        $grouped = [];

        while (($row = fgetcsv($handle)) !== false) {
            $get = fn (string $k) => isset($map[$k]) ? trim((string) ($row[$map[$k]] ?? '')) : '';

            $oid = $get('order_id');

            if ($oid === '') {
                continue;
            }

            $grouped[$oid][] = [
                'name'          => $get('item_name'),
                'sku'           => $this->nullish($get('sku')),
                'qty'           => $get('qty'),
                'line_subtotal' => $get('line_subtotal'),
                'line_total'    => $get('line_total'),
                'line_tax'      => $get('line_tax'),
            ];
        }

        fclose($handle);

        return $grouped;
    }

    /**
     * @param  array<int,array<string,string>>  $items
     */
    protected function hasCatalogProduct(array $items): bool
    {
        foreach ($items as $item) {
            $sku = $item['sku'];

            if ($sku !== null && isset($this->productBySku[strtolower($sku)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Write one order and all its child rows.
     *
     * @param  array<int,string>  $row
     * @param  array<int,array<string,string>>  $items
     * @param  array{id:int,first_name:?string,last_name:?string}|null  $customer
     * @param  array<string,int>  $stats
     */
    protected function importOrder(array $row, callable $get, string $status, array $items, ?array $customer, array &$stats): void
    {
        $createdAt = $this->date($get('date_created_gmt')) ?? now();
        $updatedAt = $this->date($get('date_updated_gmt')) ?? $createdAt;
        $currency = $this->nullish($get('currency')) ?? 'USD';
        $paid = in_array($status, $this->paidStatuses, true);
        $refunded = $status === 'closed';

        // Roll up totals from the line items.
        $subTotal = $tax = $discount = $qtyTotal = 0.0;

        foreach ($items as $it) {
            $qty = max(1, (int) $it['qty']);
            $lineSub = (float) $it['line_subtotal'];
            $lineTotal = $it['line_total'] === '' ? $lineSub : (float) $it['line_total'];
            $subTotal += $lineSub;
            $tax += (float) $it['line_tax'];
            $discount += max(0, $lineSub - $lineTotal);
            $qtyTotal += $qty;
        }

        $grandTotal = (float) $get('total_amount');
        $shipping = round($grandTotal - $subTotal + $discount - $tax, 4);

        if ($shipping < 0) {
            $shipping = 0.0;
        }

        $firstName = $customer['first_name'] ?? $this->nullish($get('billing_first_name')) ?? 'Guest';
        $lastName = $customer['last_name'] ?? $this->nullish($get('billing_last_name')) ?? '';

        $orderId = DB::table('orders')->insertGetId([
            'increment_id'          => $get('order_id'),
            'status'                => $status,
            'channel_name'          => $this->channelName,
            'is_guest'              => $customer ? 0 : 1,
            'customer_email'        => $this->nullish($get('billing_email')),
            'customer_first_name'   => $firstName,
            'customer_last_name'    => $lastName,
            'shipping_method'       => null,
            'total_item_count'      => count($items),
            'total_qty_ordered'     => (int) $qtyTotal,
            'base_currency_code'    => $currency,
            'channel_currency_code' => $currency,
            'order_currency_code'   => $currency,
            'grand_total'           => $grandTotal,
            'base_grand_total'      => $grandTotal,
            'grand_total_invoiced'      => $paid ? $grandTotal : 0,
            'base_grand_total_invoiced' => $paid ? $grandTotal : 0,
            'grand_total_refunded'      => $refunded ? $grandTotal : 0,
            'base_grand_total_refunded' => $refunded ? $grandTotal : 0,
            'sub_total'             => $subTotal,
            'base_sub_total'        => $subTotal,
            'sub_total_invoiced'      => $paid ? $subTotal : 0,
            'base_sub_total_invoiced' => $paid ? $subTotal : 0,
            'discount_amount'       => $discount,
            'base_discount_amount'  => $discount,
            'tax_amount'            => $tax,
            'base_tax_amount'       => $tax,
            'tax_amount_invoiced'      => $paid ? $tax : 0,
            'base_tax_amount_invoiced' => $paid ? $tax : 0,
            'shipping_amount'       => $shipping,
            'base_shipping_amount'  => $shipping,
            'shipping_invoiced'      => $paid ? $shipping : 0,
            'base_shipping_invoiced' => $paid ? $shipping : 0,
            'customer_id'           => $customer['id'] ?? null,
            'customer_type'         => $customer ? $this->customerType : null,
            'channel_id'            => $this->channelId,
            'channel_type'          => $this->channelType,
            'created_at'            => $createdAt,
            'updated_at'            => $updatedAt,
        ]);

        $this->insertAddresses($row, $get, $orderId, $customer);

        DB::table('order_payment')->insert([
            'order_id'     => $orderId,
            'method'       => $this->nullish($get('payment_method')) ?? 'legacy',
            'method_title' => $this->nullish($get('payment_method_title')) ?? 'Legacy (WooCommerce)',
            'additional'   => json_encode(['migrated_from' => 'woocommerce']),
            'created_at'   => $createdAt,
            'updated_at'   => $updatedAt,
        ]);

        $customer ? $stats['linked_orders']++ : $stats['guest_orders']++;

        foreach ($items as $it) {
            $this->insertItem($it, $orderId, $customer, $paid, $createdAt, $updatedAt, $stats);
            $stats['items']++;
        }
    }

    /**
     * @param  array<int,string>  $row
     * @param  array{id:int}|null  $customer
     */
    protected function insertAddresses(array $row, callable $get, int $orderId, ?array $customer): void
    {
        $mk = function (string $prefix, string $type) use ($get, $orderId, $customer): ?array {
            $addr1 = $this->nullish($get("{$prefix}_address_1"));
            $city = $this->nullish($get("{$prefix}_city"));

            if ($addr1 === null && $city === null) {
                return null;
            }

            $line2 = $this->nullish($get("{$prefix}_address_2"));

            return [
                'address_type'  => $type,
                'order_id'      => $orderId,
                'customer_id'   => $customer['id'] ?? null,
                'first_name'    => $this->nullish($get("{$prefix}_first_name")) ?? 'Guest',
                'last_name'     => $this->nullish($get("{$prefix}_last_name")) ?? '',
                'company_name'  => $this->nullish($get("{$prefix}_company")),
                'address'       => trim(($addr1 ?? '')."\n".($line2 ?? '')),
                'city'          => $city ?? '',
                'state'         => $this->nullish($get("{$prefix}_state")),
                'country'       => $this->nullish($get("{$prefix}_country")),
                'postcode'      => $this->nullish($get("{$prefix}_postcode")),
                'email'         => $this->nullish($get('billing_email')),
                'phone'         => $this->nullish($get('billing_phone')),
            ];
        };

        $billing = $mk('billing', \Webkul\Sales\Models\OrderAddress::ADDRESS_TYPE_BILLING);
        $shipping = $mk('shipping', \Webkul\Sales\Models\OrderAddress::ADDRESS_TYPE_SHIPPING);

        if ($billing) {
            DB::table('addresses')->insert($billing);
        }

        if ($shipping) {
            DB::table('addresses')->insert($shipping);
        }
    }

    /**
     * @param  array<string,string>  $it
     * @param  array{id:int}|null  $customer
     * @param  array<string,int>  $stats
     */
    protected function insertItem(array $it, int $orderId, ?array $customer, bool $paid, $createdAt, $updatedAt, array &$stats): void
    {
        $sku = $it['sku'];
        $product = ($sku !== null) ? ($this->productBySku[strtolower($sku)] ?? null) : null;

        $qty = max(1, (int) $it['qty']);
        $lineSub = (float) $it['line_subtotal'];
        $lineTotal = $it['line_total'] === '' ? $lineSub : (float) $it['line_total'];
        $lineTax = (float) $it['line_tax'];
        $unit = $qty > 0 ? round($lineSub / $qty, 4) : $lineSub;
        $discount = max(0, $lineSub - $lineTotal);

        $type = $product['type'] ?? 'simple';
        $name = $this->nullish($it['name']) ?? $product['name'] ?? ($sku ?? 'Item');

        $additional = [
            'product_id' => $product['id'] ?? null,
            'sku'        => $sku,
            'name'       => $name,
            'qty'        => $qty,
        ];

        $isDownloadable = $product
            && stripos($type, 'downloadable') !== false
            && ! empty($this->linksByProduct[$product['id']]);

        if ($isDownloadable) {
            $additional['links'] = array_column($this->linksByProduct[$product['id']], 'id');
        }

        $itemId = DB::table('order_items')->insertGetId([
            'sku'            => $sku,
            'type'           => $type,
            'name'           => $name,
            'weight'         => 0,
            'total_weight'   => 0,
            'qty_ordered'    => $qty,
            'qty_shipped'    => $paid ? $qty : 0,
            'qty_invoiced'   => $paid ? $qty : 0,
            'price'          => $unit,
            'base_price'     => $unit,
            'total'          => $lineSub,
            'base_total'     => $lineSub,
            'total_invoiced'      => $paid ? $lineSub : 0,
            'base_total_invoiced' => $paid ? $lineSub : 0,
            'discount_amount'      => $discount,
            'base_discount_amount' => $discount,
            'tax_amount'      => $lineTax,
            'base_tax_amount' => $lineTax,
            'tax_amount_invoiced'      => $paid ? $lineTax : 0,
            'base_tax_amount_invoiced' => $paid ? $lineTax : 0,
            'product_id'     => $product['id'] ?? null,
            // Morph CLASS column (what Bagisto's OrderItem->product morphTo resolves) —
            // NOT the product type string; that lives in `type` above.
            'product_type'   => $product ? \Webkul\Product\Models\Product::class : null,
            'order_id'       => $orderId,
            'additional'     => json_encode($additional),
            'created_at'     => $createdAt,
            'updated_at'     => $updatedAt,
        ]);

        if ($product) {
            $stats['items_linked']++;
        }

        if ($isDownloadable && $customer && ! $this->option('skip-downloads')) {
            foreach ($this->linksByProduct[$product['id']] as $link) {
                DB::table('downloadable_link_purchased')->insert([
                    'product_name'    => $name,
                    'name'            => $link['title'] ?? $name,
                    'url'             => $link['url'],
                    'file'            => $link['file'],
                    'file_name'       => $link['file_name'],
                    'type'            => $link['type'],
                    'download_bought' => $link['downloads'] * $qty,
                    'download_used'   => 0,
                    'status'          => 'available',
                    'customer_id'     => $customer['id'],
                    'order_id'        => $orderId,
                    'order_item_id'   => $itemId,
                    'created_at'      => $createdAt,
                    'updated_at'      => $updatedAt,
                ]);
                $stats['downloads']++;
            }
        }
    }

    /**
     * Reduce a raw Woo status to its canonical Woo key: strip the `wc-` prefix
     * and any legacy `-a` / `-a-a` suffix (e.g. `wc-completed-a` → `completed`).
     */
    protected function normaliseStatus(string $status): string
    {
        $status = strtolower(trim($status));
        $status = preg_replace('/^wc-/', '', $status);
        $status = preg_replace('/(-a)+$/', '', $status);

        return $status;
    }

    protected function date(?string $value): ?Carbon
    {
        $value = $this->nullish((string) $value);

        if ($value === null || str_starts_with($value, '0000')) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function nullish(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return ($value === '' || strtoupper($value) === 'NULL') ? null : $value;
    }

    /**
     * @param  array<string,int>  $stats
     */
    protected function renderReport(array $stats, bool $dryRun): void
    {
        $this->newLine();
        $this->info($dryRun ? 'Dry run complete.' : 'Import complete.');

        $this->table(['Metric', 'Count'], [
            ['Order rows read', number_format($stats['read'])],
            ['Orders imported', number_format($stats['imported'])],
            ['  → linked to a customer', number_format($stats['linked_orders'])],
            ['  → guest (no matching email)', number_format($stats['guest_orders'])],
            ['Line items written', number_format($stats['items'])],
            ['  → linked to a product', number_format($stats['items_linked'])],
            ['Digital access rows (downloads)', number_format($stats['downloads'])],
            ['Skipped — already imported', number_format($stats['skipped_existing'])],
            ['Skipped — no catalog product', number_format($stats['skipped_no_product'])],
            ['Skipped — failed order', number_format($stats['skipped_failed'])],
            ['Skipped — draft/other status', number_format($stats['skipped_status'])],
            ['Errors', number_format($stats['errors'])],
        ]);

        if ($dryRun) {
            $this->warn('DRY RUN — nothing was written.');
        }
    }
}
