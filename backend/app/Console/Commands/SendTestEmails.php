<?php

/**
 * ============================================================
 * SendTestEmails.php — Send Test Notification Emails
 * ============================================================
 * What this file does:
 *   Sends all 7 types of notification emails to a test
 *   email address so you can preview how they look. Uses
 *   a real order from the database, or creates fake sample
 *   data if no orders exist.
 *
 * How to run: php artisan mail:test admin@example.com
 * ============================================================
 */

namespace App\Console\Commands;

use App\Mail\OrderCancellation;
use App\Mail\OrderConfirmation;
use App\Mail\OrderRefund;
use App\Mail\OrderStatusUpdate;
use App\Mail\PaymentConfirmation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Brand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendTestEmails extends Command
{
    protected $signature = 'mail:test {email}';

    protected $description = 'Send all test notification emails to a given address';

    // Main method — sends all test emails one by one
    public function handle()
    {
        $email = $this->argument('email');

        // Try to get a real order, otherwise create a fake one in memory
        try {
            $order = Order::with('items')->latest()->first();
        } catch (\Exception $e) {
            $order = null;
        }

        if (! $order) {
            $this->info('No DB connection or no orders — using sample order data.');
            $order = new Order;
            $order->order_number = Brand::orderPrefix().'1001';
            $order->customer_name = 'Test Customer';
            $order->customer_email = $email;
            $order->customer_phone = '+1 555 010 0000';
            $order->payment_method = 'paymennt';
            $order->payment_status = 'paid';
            $order->status = 'processing';
            $order->subtotal = 1250.00;
            $order->discount = 100.00;
            $order->shipping_cost = 0.00;
            $order->total = 1150.00;
            $order->shipping_address = '123 Sample Street';
            $order->shipping_city = 'Sample City';
            $order->shipping_country = Brand::countryCode();
            $order->tracking_number = 'TRK-98765432';
            $order->created_at = now();  // invoice PDF formats this date; sample order needs it set

            $item1 = new OrderItem;
            $item1->product_name = 'Rolex Submariner Date';
            $item1->product_image = Brand::logoUrl();  // neutral placeholder — no real product photos in sample data
            $item1->quantity = 1;
            $item1->unit_price = 850.00;
            $item1->total_price = 850.00;

            $item2 = new OrderItem;
            $item2->product_name = 'Omega Speedmaster Professional';
            $item2->product_image = Brand::logoUrl();
            $item2->quantity = 1;
            $item2->unit_price = 400.00;
            $item2->total_price = 400.00;

            $order->setRelation('items', collect([$item1, $item2]));
        }

        $this->info("Using order: {$order->order_number}");
        $this->info("Sending test emails to: {$email}");
        $this->newLine();

        // 1. Order Confirmation
        $this->info('1/7 Sending Order Confirmation...');
        try {
            Mail::to($email)->send(new OrderConfirmation($order));
            $this->info('    ✓ Order Confirmation sent!');
        } catch (\Exception $e) {
            $this->error('    ✗ Failed: '.$e->getMessage());
        }

        // 2. Payment Confirmed
        $this->info('2/7 Sending Payment Confirmed...');
        try {
            Mail::to($email)->send(new PaymentConfirmation($order, 'paid'));
            $this->info('    ✓ Payment Confirmed sent!');
        } catch (\Exception $e) {
            $this->error('    ✗ Failed: '.$e->getMessage());
        }

        // 3. Payment Failed
        $this->info('3/7 Sending Payment Failed...');
        try {
            Mail::to($email)->send(new PaymentConfirmation($order, 'failed'));
            $this->info('    ✓ Payment Failed sent!');
        } catch (\Exception $e) {
            $this->error('    ✗ Failed: '.$e->getMessage());
        }

        // 4. Order Status - Processing
        $this->info('4/7 Sending Order Status Update (Processing)...');
        try {
            Mail::to($email)->send(new OrderStatusUpdate($order, 'processing'));
            $this->info('    ✓ Order Processing sent!');
        } catch (\Exception $e) {
            $this->error('    ✗ Failed: '.$e->getMessage());
        }

        // 5. Order Status - Shipped
        $this->info('5/7 Sending Order Status Update (Shipped)...');
        try {
            Mail::to($email)->send(new OrderStatusUpdate($order, 'shipped'));
            $this->info('    ✓ Order Shipped sent!');
        } catch (\Exception $e) {
            $this->error('    ✗ Failed: '.$e->getMessage());
        }

        // 6. Order Cancellation
        $this->info('6/7 Sending Order Cancellation...');
        try {
            Mail::to($email)->send(new OrderCancellation($order));
            $this->info('    ✓ Order Cancellation sent!');
        } catch (\Exception $e) {
            $this->error('    ✗ Failed: '.$e->getMessage());
        }

        // 7. Order Refund
        $this->info('7/7 Sending Order Refund...');
        try {
            Mail::to($email)->send(new OrderRefund($order));
            $this->info('    ✓ Order Refund sent!');
        } catch (\Exception $e) {
            $this->error('    ✗ Failed: '.$e->getMessage());
        }

        $this->newLine();
        $this->info('Done! Check inbox at: '.$email);

        return 0;
    }
}
