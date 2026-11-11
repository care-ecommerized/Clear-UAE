<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Imports a Shopify "orders_export" CSV as order history.
 *
 * Imported orders are records of the past, so this saves them directly and
 * deliberately skips everything a new order triggers: no customer or admin
 * emails, no stock changes and no payment calls. `payment_method` never
 * equals a live gateway slug and `payment_reference` stays empty, so a later
 * cancel cannot send a refund to a gateway. No tracking number is set, so
 * orders:sync-delivery-status leaves them alone.
 *
 * Send dry_run=1 for a preview that saves nothing. An order is "already here"
 * when its number exists with the same placed time, so the same file can be
 * uploaded twice safely.
 *
 * Two Shopify stores reused the same numbers, so a number can exist for a
 * different order. That order is saved under the CLASH_PREFIX instead
 * (clear1332 -> backup1332), with the Shopify number kept in its notes.
 * Order::booted() only counts numbers with the store's own prefix, so these
 * never move the sequence for new orders.
 */
class ShopifyOrderImportController extends Controller
{
    private const CLASH_PREFIX = 'backup';

    private const REQUIRED = ['Name', 'Financial Status', 'Total', 'Created at', 'Lineitem name', 'Lineitem quantity', 'Lineitem price'];

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:51200',
            'dry_run' => 'sometimes|boolean',
        ]);
        set_time_limit(300);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $headers = fgetcsv($handle, 0, ',', '"', '') ?: [];
        if ($headers) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        }
        $headers = array_map('trim', $headers);

        $missing = array_values(array_diff(self::REQUIRED, $headers));
        if ($missing) {
            fclose($handle);

            return response()->json([
                'message' => 'This is not a Shopify orders export. Missing columns: '.implode(', ', $missing),
            ], 422);
        }

        // Shopify writes one row per line item. Only an order's first row has
        // the totals and address; the others just repeat its Name.
        $orders = [];
        $width = count($headers);
        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($cells === [null]) {
                continue;
            }
            $row = array_combine($headers, array_slice(array_pad($cells, $width, ''), 0, $width));
            $name = trim((string) $row['Name']);
            if ($name === '') {
                continue;
            }
            $orders[$name] ??= ['head' => $row, 'lines' => []];
            if (trim((string) $row['Lineitem name']) !== '') {
                $orders[$name]['lines'][] = $row;
            }
        }
        fclose($handle);

        if (! $orders) {
            return response()->json(['message' => 'This file has no orders in it.'], 422);
        }

        // Raw strings: created_at is stored in UTC whatever the app timezone is.
        $existing = DB::table('orders')->pluck('created_at', 'order_number');
        [$bySku, $byName] = $this->productLookups();

        $summary = [
            'orders_in_file' => count($orders),
            'new_orders' => 0,
            'already_imported' => 0,
            'renumbered' => 0,
            'line_items' => 0,
            'unmatched_items' => 0,
            'no_email' => 0,
            'other_currency' => 0,
            'total_value' => 0.0,
            'statuses' => [],
            'payment_statuses' => [],
            'first_order' => null,
            'last_order' => null,
            'oldest_date' => null,
            'newest_date' => null,
            'imported' => 0,
            'errors' => [],
        ];

        $dryRun = $request->boolean('dry_run');

        foreach ($orders as $name => $data) {
            $shopifyNumber = ltrim($name, '# ');

            try {
                $placed = $this->parseDate($data['head']['Created at'] ?? '');
                $number = $this->numberToSave($shopifyNumber, $placed, $existing);
                if ($number === null) {
                    $summary['already_imported']++;

                    continue;
                }
                $fields = $this->orderFields($number, $data['head']);
            } catch (\Throwable $e) {
                $summary['errors'][] = "{$name}: could not read this order";

                continue;
            }

            if ($number !== $shopifyNumber) {
                $fields['notes'] .= "\nShopify order number: {$shopifyNumber}";
                $summary['renumbered']++;
            }

            $lines = [];
            foreach ($data['lines'] as $line) {
                $product = $this->matchProduct($line, $bySku, $byName);
                $quantity = max(1, (int) ($line['Lineitem quantity'] ?? 1));
                $price = $this->money($line['Lineitem price'] ?? 0);
                $lines[] = [
                    'product_id' => $product?->id,
                    'product_name' => mb_substr(trim((string) $line['Lineitem name']), 0, 255),
                    'product_image' => $product?->image_url,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'total_price' => round($price * $quantity, 2),
                ];
                $summary['unmatched_items'] += $product ? 0 : 1;
            }

            $summary['new_orders']++;
            $summary['line_items'] += count($lines);
            $summary['no_email'] += $fields['customer_email'] === '' ? 1 : 0;
            $summary['other_currency'] += $this->isOtherCurrency($data['head']) ? 1 : 0;
            $summary['total_value'] += $fields['status'] === 'cancelled' ? 0 : $fields['total'];
            $summary['statuses'][$fields['status']] = ($summary['statuses'][$fields['status']] ?? 0) + 1;
            $summary['payment_statuses'][$fields['payment_status']] = ($summary['payment_statuses'][$fields['payment_status']] ?? 0) + 1;
            $summary['first_order'] ??= $number;
            $summary['last_order'] = $number;
            if (! $summary['oldest_date'] || $placed->lt($summary['oldest_date'])) {
                $summary['oldest_date'] = $placed->copy();
            }
            if (! $summary['newest_date'] || $placed->gt($summary['newest_date'])) {
                $summary['newest_date'] = $placed->copy();
            }

            if ($dryRun) {
                continue;
            }

            try {
                DB::transaction(function () use ($fields, $placed, $lines) {
                    $order = new Order;
                    $order->forceFill($fields);
                    $order->created_at = $placed;
                    $order->updated_at = $placed;
                    $order->save();
                    foreach ($lines as $line) {
                        OrderItem::create($line + ['order_id' => $order->id]);
                    }
                    $order->logEvent('status', 'Imported from Shopify', null, 'Import');
                });
                $summary['imported']++;
            } catch (\Throwable $e) {
                report($e);
                $summary['errors'][] = "{$name}: could not be saved";
            }
        }

        $summary['total_value'] = round($summary['total_value'], 2);
        $summary['oldest_date'] = $summary['oldest_date']?->toIso8601String();
        $summary['newest_date'] = $summary['newest_date']?->toIso8601String();
        $summary['error_count'] = count($summary['errors']);
        $summary['errors'] = array_slice($summary['errors'], 0, 20);

        return response()->json($summary);
    }

    /**
     * The number to save this order under, or null when it is already here.
     *
     * @throws \RuntimeException when both numbers belong to other orders
     */
    private function numberToSave(string $number, Carbon $placed, Collection $existing): ?string
    {
        foreach ([$number, preg_replace('/^\D+/', self::CLASH_PREFIX, $number)] as $candidate) {
            if (! $existing->has($candidate)) {
                return $candidate;
            }
            if (abs(Carbon::parse($existing[$candidate], 'UTC')->diffInSeconds($placed)) < 60) {
                return null;
            }
        }

        throw new \RuntimeException("No free number for {$number}");
    }

    /** @return array<string, mixed> */
    private function orderFields(string $number, array $row): array
    {
        $financial = strtolower(trim((string) ($row['Financial Status'] ?? '')));
        $fulfilment = strtolower(trim((string) ($row['Fulfillment Status'] ?? '')));
        $address = implode(', ', array_filter([
            trim((string) ($row['Shipping Address1'] ?? '')),
            trim((string) ($row['Shipping Address2'] ?? '')),
        ]));

        return [
            'order_number' => $number,
            'customer_email' => strtolower(trim((string) ($row['Email'] ?? ''))),
            'customer_name' => $this->first($row, ['Shipping Name', 'Billing Name']),
            'customer_phone' => ltrim((string) $this->first($row, ['Shipping Phone', 'Phone', 'Billing Phone']), "' ") ?: null,
            'subtotal' => $this->money($row['Subtotal'] ?? 0),
            'discount' => $this->money($row['Discount Amount'] ?? 0),
            'shipping_cost' => $this->money($row['Shipping'] ?? 0),
            'shipping_method' => mb_substr(trim((string) ($row['Shipping Method'] ?? '')), 0, 255) ?: null,
            'total' => $this->money($row['Total'] ?? 0),
            'status' => $this->status($financial, $fulfilment, trim((string) ($row['Cancelled at'] ?? ''))),
            'payment_status' => $this->paymentStatus($financial),
            'payment_method' => $this->paymentMethod((string) ($row['Payment Method'] ?? '')),
            'shipping_address' => $address ?: ($this->first($row, ['Shipping Street', 'Billing Street', 'Billing Address1'])),
            'shipping_city' => $this->first($row, ['Shipping City', 'Billing City']),
            'shipping_state' => $this->first($row, ['Shipping Province Name', 'Shipping Province']),
            'shipping_country' => $this->first($row, ['Shipping Country', 'Billing Country']) ?: 'UAE',
            'shipping_zip' => $this->first($row, ['Shipping Zip', 'Billing Zip']),
            'notes' => $this->notes($row),
        ];
    }

    private function status(string $financial, string $fulfilment, string $cancelledAt): string
    {
        if ($cancelledAt !== '' || $financial === 'voided' || $fulfilment === 'restocked') {
            return 'cancelled';
        }
        if ($fulfilment === 'fulfilled') {
            return 'delivered';
        }
        // Money went back and nothing shipped: not an order to work on.
        if ($financial === 'refunded') {
            return 'cancelled';
        }

        return $fulfilment === 'partial' ? 'processing' : 'confirmed';
    }

    private function paymentStatus(string $financial): string
    {
        return match ($financial) {
            'paid', 'partially_refunded' => 'paid',
            'refunded' => 'refunded',
            'voided' => 'failed',
            default => 'pending',
        };
    }

    /** Never one of the live gateway slugs; see the class comment. */
    private function paymentMethod(string $method): string
    {
        $method = trim($method);
        if (preg_match('/cash|\bcod\b/i', $method)) {
            return 'cod';
        }

        return mb_substr('Shopify'.($method !== '' ? ': '.$method : ''), 0, 255);
    }

    private function notes(array $row): string
    {
        $notes = ['Imported from Shopify.'];
        if (trim((string) ($row['Notes'] ?? '')) !== '') {
            $notes[] = trim((string) $row['Notes']);
        }
        if (trim((string) ($row['Tags'] ?? '')) !== '') {
            $notes[] = 'Tags: '.trim((string) $row['Tags']);
        }
        if ($this->money($row['Refunded Amount'] ?? 0) > 0) {
            $notes[] = 'Refunded: '.trim((string) $row['Refunded Amount']);
        }
        if ($this->isOtherCurrency($row)) {
            $notes[] = 'Original currency: '.trim((string) $row['Currency']);
        }

        return implode("\n", $notes);
    }

    private function isOtherCurrency(array $row): bool
    {
        $currency = strtoupper(trim((string) ($row['Currency'] ?? '')));

        return $currency !== '' && $currency !== 'AED';
    }

    private function parseDate(string $value): Carbon
    {
        return Carbon::parse(trim($value))->utc();
    }

    private function money(mixed $value): float
    {
        return round((float) str_replace(',', '', (string) $value), 2);
    }

    private function first(array $row, array $columns): ?string
    {
        foreach ($columns as $column) {
            $value = trim((string) ($row[$column] ?? ''));
            if ($value !== '') {
                return mb_substr($value, 0, 500);
            }
        }

        return null;
    }

    /** @return array{0: array<string, Product>, 1: array<string, Product>} */
    private function productLookups(): array
    {
        $bySku = [];
        $byName = [];
        foreach (Product::get(['id', 'name', 'sku', 'image_url']) as $product) {
            if ($product->sku) {
                $bySku[mb_strtolower(trim($product->sku))] = $product;
            }
            $byName[mb_strtolower(trim($product->name))] = $product;
        }

        return [$bySku, $byName];
    }

    /** By SKU, then name, then name without Shopify's " - Variant" ending. */
    private function matchProduct(array $line, array $bySku, array $byName): ?Product
    {
        $sku = mb_strtolower(trim((string) ($line['Lineitem sku'] ?? '')));
        if ($sku !== '' && isset($bySku[$sku])) {
            return $bySku[$sku];
        }
        $name = mb_strtolower(trim((string) $line['Lineitem name']));
        if (isset($byName[$name])) {
            return $byName[$name];
        }
        $cut = mb_strrpos($name, ' - ');

        return $cut === false ? null : ($byName[mb_substr($name, 0, $cut)] ?? null);
    }
}
