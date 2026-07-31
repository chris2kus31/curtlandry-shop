<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import WooCommerce/WordPress customers (and their billing/shipping addresses)
 * into Bagisto from the CSV produced by the migration export query.
 *
 * The WordPress password hash is stored verbatim in customers.password; the
 * customer is transparently upgraded to Bagisto's bcrypt format on first login
 * (see App\Auth\WordPressHasher + WordPressEloquentUserProvider).
 *
 * Expected CSV header (order independent, matched by name):
 *   wp_user_id, email, password_hash, created_at,
 *   first_name, last_name,
 *   billing_first_name, billing_last_name, billing_company,
 *   billing_address_1, billing_address_2, billing_city, billing_state,
 *   billing_postcode, billing_country, billing_phone,
 *   shipping_first_name, shipping_last_name, shipping_company,
 *   shipping_address_1, shipping_address_2, shipping_city, shipping_state,
 *   shipping_postcode, shipping_country
 */
class ImportWooCustomers extends Command
{
    protected $signature = 'woo:import-customers
        {file : Absolute path to the exported customers CSV}
        {--offset=0 : Skip this many data rows before importing}
        {--limit=0 : Import at most this many rows (0 = no limit)}
        {--chunk=500 : Number of rows per database transaction}
        {--dry-run : Parse and report without writing anything}';

    protected $description = 'Import WooCommerce customers and addresses from a CSV export';

    /**
     * Resolved customer_group_id for imported customers (general group).
     */
    protected ?int $customerGroupId = null;

    /**
     * Resolved default channel_id for imported customers.
     */
    protected ?int $channelId = null;

    /**
     * Lowercased set of emails that already exist in the customers table.
     *
     * @var array<string, true>
     */
    protected array $existingEmails = [];

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
        $chunkSize = max(1, (int) $this->option('chunk'));

        $this->customerGroupId = DB::table('customer_groups')->where('code', 'general')->value('id') ?? 2;
        $this->channelId = DB::table('channels')->min('id');

        $this->existingEmails = DB::table('customers')
            ->pluck('email')
            ->filter()
            ->mapWithKeys(fn ($email) => [strtolower(trim($email)) => true])
            ->all();

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

        $missing = array_diff(['email', 'password_hash'], array_keys($map));

        if ($missing) {
            $this->error('CSV is missing required columns: '.implode(', ', $missing));
            fclose($handle);

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('DRY RUN — no data will be written.');
        }

        $stats = [
            'read' => 0,
            'imported' => 0,
            'skipped_existing' => 0,
            'skipped_invalid' => 0,
            'addresses' => 0,
        ];

        // Track emails seen within this file to avoid in-file duplicates.
        $seen = [];

        $rowIndex = -1;
        $customerBatch = [];
        $rawRows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rowIndex++;

            if ($rowIndex < $offset) {
                continue;
            }

            if ($limit > 0 && $stats['read'] >= $limit) {
                break;
            }

            $stats['read']++;

            $record = $this->extractRecord($row, $map);

            $email = strtolower(trim((string) ($record['email'] ?? '')));

            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $stats['skipped_invalid']++;

                continue;
            }

            if (isset($this->existingEmails[$email]) || isset($seen[$email])) {
                $stats['skipped_existing']++;

                continue;
            }

            $seen[$email] = true;
            $rawRows[] = $record;

            if (count($rawRows) >= $chunkSize) {
                $this->flushChunk($rawRows, $dryRun, $stats);
                $rawRows = [];
            }
        }

        if ($rawRows) {
            $this->flushChunk($rawRows, $dryRun, $stats);
        }

        fclose($handle);

        $this->newLine();
        $this->info('Import complete.');
        $this->table(
            ['Rows read', 'Imported', 'Skipped (existing)', 'Skipped (invalid)', 'Addresses'],
            [[
                $stats['read'],
                $stats['imported'],
                $stats['skipped_existing'],
                $stats['skipped_invalid'],
                $stats['addresses'],
            ]]
        );

        if ($dryRun) {
            $this->warn('DRY RUN — nothing was written.');
        }

        return self::SUCCESS;
    }

    /**
     * Insert a chunk of customer records (and their addresses) in one transaction.
     *
     * @param  array<int, array<string, string|null>>  $rows
     * @param  array<string, int>  $stats
     */
    protected function flushChunk(array $rows, bool $dryRun, array &$stats): void
    {
        if ($dryRun) {
            foreach ($rows as $record) {
                $stats['imported']++;
                $stats['addresses'] += count($this->buildAddresses($record, 0));
            }

            return;
        }

        DB::transaction(function () use ($rows, &$stats) {
            $now = now();

            foreach ($rows as $record) {
                $customerId = DB::table('customers')->insertGetId(
                    $this->buildCustomer($record, $now)
                );

                $stats['imported']++;

                $addresses = $this->buildAddresses($record, $customerId, $now);

                if ($addresses) {
                    DB::table('addresses')->insert($addresses);
                    $stats['addresses'] += count($addresses);
                }
            }
        });
    }

    /**
     * Build a customers table row from an export record.
     *
     * @param  array<string, string|null>  $record
     * @return array<string, mixed>
     */
    protected function buildCustomer(array $record, Carbon $now): array
    {
        $firstName = $this->firstNonEmpty([
            $record['first_name'] ?? null,
            $record['billing_first_name'] ?? null,
            $this->nameFromEmail($record['email'] ?? ''),
        ]) ?? 'Customer';

        $lastName = $this->firstNonEmpty([
            $record['last_name'] ?? null,
            $record['billing_last_name'] ?? null,
        ]) ?? '';

        $createdAt = $this->parseDate($record['created_at'] ?? null) ?? $now;

        return [
            'first_name'                => $firstName,
            'last_name'                 => $lastName,
            'email'                     => strtolower(trim((string) $record['email'])),
            // Stored verbatim; upgraded to bcrypt on first login.
            'password'                  => $record['password_hash'] ?: null,
            'customer_group_id'         => $this->customerGroupId,
            'channel_id'                => $this->channelId,
            'status'                    => 1,
            'is_verified'               => 1,
            'is_suspended'              => 0,
            'subscribed_to_news_letter' => 0,
            'created_at'                => $createdAt,
            'updated_at'                => $now,
        ];
    }

    /**
     * Build address rows (billing + shipping) for a customer.
     *
     * @param  array<string, string|null>  $record
     * @return array<int, array<string, mixed>>
     */
    protected function buildAddresses(array $record, int $customerId, ?Carbon $now = null): array
    {
        $now ??= now();
        $addresses = [];

        $billing = $this->buildAddress($record, 'billing', $customerId, true, $now);

        if ($billing) {
            $addresses[] = $billing;
        }

        $shipping = $this->buildAddress($record, 'shipping', $customerId, false, $now);

        // Only add shipping if it is present and not identical to billing.
        if ($shipping && (! $billing || $shipping['address'] !== $billing['address'] || $shipping['city'] !== $billing['city'])) {
            $addresses[] = $shipping;
        }

        return $addresses;
    }

    /**
     * Build a single address row for the given prefix (billing|shipping).
     *
     * Returns null when the mandatory fields (address + city) are absent.
     *
     * @param  array<string, string|null>  $record
     * @return array<string, mixed>|null
     */
    protected function buildAddress(array $record, string $prefix, int $customerId, bool $isDefault, Carbon $now): ?array
    {
        $line1 = $this->clean($record["{$prefix}_address_1"] ?? null);
        $line2 = $this->clean($record["{$prefix}_address_2"] ?? null);
        $city = $this->clean($record["{$prefix}_city"] ?? null);

        if ($line1 === null || $city === null) {
            return null;
        }

        $address = $line2 !== null ? $line1."\n".$line2 : $line1;

        $firstName = $this->firstNonEmpty([
            $record["{$prefix}_first_name"] ?? null,
            $record['first_name'] ?? null,
        ]) ?? 'Customer';

        $lastName = $this->firstNonEmpty([
            $record["{$prefix}_last_name"] ?? null,
            $record['last_name'] ?? null,
        ]) ?? '';

        return [
            'address_type'    => 'customer',
            'customer_id'     => $customerId,
            'first_name'      => $firstName,
            'last_name'       => $lastName,
            'company_name'    => $this->clean($record["{$prefix}_company"] ?? null),
            'address'         => $address,
            'city'            => $city,
            'state'           => $this->clean($record["{$prefix}_state"] ?? null),
            'country'         => $this->clean($record["{$prefix}_country"] ?? null),
            'postcode'        => $this->clean($record["{$prefix}_postcode"] ?? null),
            'email'           => strtolower(trim((string) $record['email'])),
            'phone'           => $this->clean($record["{$prefix}_phone"] ?? $record['billing_phone'] ?? null),
            'default_address' => $isDefault ? 1 : 0,
            'use_for_shipping' => 0,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];
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
            $key = strtolower(trim((string) $name));

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
     * Return the first non-empty trimmed value from a list, or null.
     *
     * @param  array<int, string|null>  $values
     */
    protected function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            $clean = $this->clean($value);

            if ($clean !== null) {
                return $clean;
            }
        }

        return null;
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
     * Derive a display name from an email local-part as a last resort.
     */
    protected function nameFromEmail(string $email): ?string
    {
        $local = strstr($email, '@', true);

        return $local ? ucfirst($local) : null;
    }

    /**
     * Parse a WooCommerce datetime string into a Carbon instance.
     */
    protected function parseDate(?string $value): ?Carbon
    {
        $value = $this->clean($value);

        if ($value === null || str_starts_with($value, '0000')) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
