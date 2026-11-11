<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Console\Command;

class AddWristSizeVariations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:add-wrist-size-variations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Extract wrist sizes from product descriptions and add them as variations';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $products = Product::all();
        $addedCount = 0;
        $skippedCount = 0;

        foreach ($products as $product) {
            // Check if product already has a wrist size variation
            if ($product->variations()->where('variation_type', 'Wrist Size')->exists()) {
                $this->line("⊘ {$product->name} — already has Wrist Size variation");
                $skippedCount++;

                continue;
            }

            // Extract wrist size from description
            $wristSize = $this->extractWristSize($product->description);

            if ($wristSize) {
                // Create the variation with stock quantity of 1
                ProductVariation::create([
                    'product_id' => $product->id,
                    'variation_type' => 'Wrist Size',
                    'variation_value' => $wristSize,
                    'stock_quantity' => 1,
                    'is_active' => true,
                    'sort_order' => 1,
                ]);

                $this->line("✓ {$product->name} — added Wrist Size: {$wristSize}");
                $addedCount++;
            } else {
                $this->line("✗ {$product->name} — no wrist size found");
                $skippedCount++;
            }
        }

        $this->newLine();
        $this->info("Summary: {$addedCount} variations added, {$skippedCount} products skipped");
    }

    /**
     * Extract wrist size from product description using pattern matching.
     */
    private function extractWristSize(?string $description): ?string
    {
        if (! $description) {
            return null;
        }

        // Pattern 1: "Wrist Size: 17.5" or similar
        if (preg_match('/[Ww]rist\s+[Ss]ize[:\s]+([0-9.]+)/i', $description, $matches)) {
            return $matches[1];
        }

        // Pattern 2: "Adjustable on request"
        if (preg_match('/[Aa]djustable\s+on\s+request/i', $description)) {
            return 'Adjustable on request';
        }

        // Pattern 3: Just a measurement number like "17.5mm" or "17.5"
        if (preg_match('/\b([0-9]{1,2}\.[0-9]{1,2})\s*(?:mm)?\b/i', $description, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
