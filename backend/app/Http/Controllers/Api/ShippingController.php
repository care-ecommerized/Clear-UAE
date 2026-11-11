<?php

/**
 * ============================================================
 * SHIPPING CONTROLLER — Shipping Rates, Shipments & Tracking
 * ============================================================
 * What this controller does:
 *   Manages everything related to shipping:
 *   - Delivery types (standard, express, etc.)
 *   - Shipping rate lookups
 *   - Creating shipments with Topex (UAE courier) or Aramex
 *   - Tracking shipments
 *   - Fetching city/area lists from Topex
 *
 * API endpoints:
 *   GET    /api/shipping/delivery-types       — List delivery types
 *   POST   /api/shipping/delivery-types       — Create delivery type (admin)
 *   PUT    /api/shipping/delivery-types/{id}  — Update delivery type (admin)
 *   DELETE /api/shipping/delivery-types/{id}  — Delete delivery type (admin)
 *   GET    /api/shipping/rates                — List shipping rates
 *   POST   /api/shipping/rates                — Create shipping rates (admin)
 *   POST   /api/shipping/calculate-rate       — Calculate shipping cost
 *   POST   /api/shipping/create-shipment      — Create a shipment (Topex/Aramex)
 *   POST   /api/shipping/track                — Track a shipment
 *   GET    /api/shipping/topex/cities         — Get Topex city list
 *   GET    /api/shipping/topex/areas          — Get Topex area list for a city
 * ============================================================
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryType;
use App\Models\ShippingRate;
use App\Support\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ShippingController extends Controller
{
    // ============ Delivery Types ============

    /**
     * GET /api/shipping/delivery-types
     * Lists all delivery types (e.g. "Standard", "Express").
     * By default shows only active types. Add ?all to see all.
     */
    public function deliveryTypes(Request $request)
    {
        $query = DeliveryType::orderBy('sort_order');
        if (! $request->has('all')) {
            $query->where('is_active', true);
        }

        return response()->json($query->get());
    }

    /**
     * POST /api/shipping/delivery-types
     * Creates a new delivery type. Admin only.
     */
    public function storeDeliveryType(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'label' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'estimated_days' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        return response()->json(DeliveryType::create($validated), 201);
    }

    /**
     * PUT /api/shipping/delivery-types/{id}
     * Updates an existing delivery type. Admin only.
     */
    public function updateDeliveryType(Request $request, string $id)
    {
        $type = DeliveryType::findOrFail($id);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'label' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'estimated_days' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);
        $type->update($validated);

        return response()->json($type);
    }

    /**
     * DELETE /api/shipping/delivery-types/{id}
     * Deletes a delivery type. Admin only.
     */
    public function destroyDeliveryType(string $id)
    {
        DeliveryType::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    // ============ Shipping Rates ============

    /**
     * GET /api/shipping/rates
     * Lists all shipping rates. By default shows only active rates.
     */
    public function rates(Request $request)
    {
        $query = ShippingRate::orderBy('sort_order');
        if (! $request->has('all')) {
            $query->where('is_active', true);
        }

        return response()->json($query->get());
    }

    /**
     * POST /api/shipping/rates
     * Creates new shipping rates in bulk. Admin only.
     * Send an array of rate objects with destination_country, delivery_type, and cost.
     */
    public function storeRates(Request $request)
    {
        $request->validate([
            'rates' => 'required|array',
            'rates.*.destination_country' => 'required|string|max:10',
            'rates.*.delivery_type' => 'required|string|max:255',
            'rates.*.cost' => 'required|numeric|min:0',
        ]);

        $created = [];
        foreach ($request->rates as $rate) {
            $created[] = ShippingRate::create(array_merge([
                'origin_country' => 'AE',
                'currency' => Brand::currency(),
            ], $rate));
        }

        return response()->json($created, 201);
    }

    /**
     * PUT /api/shipping/rates/{id}
     * Updates a single shipping rate. Used by the admin UI for inline edits
     * and the active-toggle switch (which sends just {is_active}). All fields
     * are optional so partial updates work.
     */
    public function updateRate(Request $request, string $id)
    {
        $rate = ShippingRate::findOrFail($id);

        $validated = $request->validate([
            'origin_country' => 'sometimes|string|max:10',
            'destination_country' => 'sometimes|string|max:10',
            'delivery_type' => 'sometimes|string|max:255',
            'min_weight' => 'sometimes|nullable|numeric|min:0',
            'max_weight' => 'sometimes|nullable|numeric|min:0',
            'cost' => 'sometimes|numeric|min:0',
            'currency' => 'sometimes|string|max:10',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer',
        ]);

        $rate->update($validated);

        return response()->json($rate);
    }

    /**
     * DELETE /api/shipping/rates/{id}
     * Permanently removes a shipping rate.
     */
    public function destroyRate(string $id)
    {
        $rate = ShippingRate::findOrFail($id);
        $rate->delete();

        return response()->json(['success' => true]);
    }

    // ============ Topex Methods ============

    /**
     * Reads Topex login credentials from .env (via config/services.php).
     * Credentials are NOT stored in the DB or admin UI any more — rotate
     * them by editing .env and running `php artisan config:clear`.
     */
    private function getTopexCredentials(): array
    {
        return [
            'username' => (string) config('services.topex.username', ''),
            'password' => (string) config('services.topex.password', ''),
            'account_number' => (string) config('services.topex.account_number', ''),
        ];
    }

    /**
     * Gets an authentication token from Topex API.
     * The token is cached for ~1 hour to avoid repeated login calls.
     */
    private function getTopexToken(): string
    {
        return Cache::remember('topex_auth_token', 3500, function () {
            $creds = $this->getTopexCredentials();

            $response = Http::asForm()->post(
                'http://topex-shipperapi.dispatchex.com/GetAuthToken',
                [
                    'Username' => $creds['username'],
                    'Password' => $creds['password'],
                    'grant_type' => 'password',
                    'AccountNumber' => $creds['account_number'],
                ]
            );

            $data = $response->json();

            return $data['access_token'] ?? '';
        });
    }

    /**
     * Creates a shipment through the Topex courier API.
     * Sends recipient details to Topex and returns a tracking number.
     */
    /** Cash the courier must collect: an unpaid Cash on Delivery order's total. */
    private function cashToCollect(Request $request): float
    {
        $order = \App\Models\Order::where('order_number', $request->input('orderId'))->first();

        return $order && $order->payment_method === 'cod' && $order->payment_status === 'pending'
            ? (float) $order->total
            : 0.0;
    }

    private function handleTopexShipment(Request $request)
    {
        $creds = $this->getTopexCredentials();
        if (! $creds['username'] || ! $creds['password']) {
            return response()->json(['success' => false, 'error' => 'Topex not configured'], 400);
        }

        try {
            $token = $this->getTopexToken();
            if (! $token) {
                return response()->json(['success' => false, 'error' => 'Failed to authenticate with Topex'], 500);
            }

            // Free-text city/area come straight from the order's shipping address.
            // Try to canonicalize against Topex's official lists (case-insensitive)
            // so a "abu dhabi" entry still matches Topex's "Abu Dhabi".
            $rawCity = trim((string) $request->input('city', ''));
            $rawArea = trim((string) $request->input('area', ''));
            $cityName = $this->matchTopexCity($token, $rawCity) ?? $rawCity;

            // Don't send Area if it's just a copy of the city — that always
            // fails Topex's area validation and leaves the cell blank anyway.
            $areaCandidate = strtolower($rawArea) === strtolower($cityName) ? '' : $rawArea;
            $areaName = $areaCandidate === ''
                ? ''
                : ($this->matchTopexArea($token, $cityName, $areaCandidate) ?? $areaCandidate);

            $weight = (float) $request->input('weight', 0.5);

            // Goods description for the AWB "Description of goods" cell.
            //
            // This used to list the actual line items ("1 x Rolex Submariner").
            // The AWB is printed and stuck to the OUTSIDE of the parcel, so that
            // told everyone handling it exactly which valuable watch was inside.
            // We now send a neutral description instead — enough for the courier
            // and customs, without advertising the contents.
            //
            // NOTE (2026-06-15): this cell only prints once Topex enables the
            // description mapping on their side; until then it stays blank
            // whatever we send (NDD/Services already works).
            $description = config('services.shipping.goods_description', 'General Merchandise');

            // NOTE: the AWB "Services" (NDD) and "Product (DOM/CDS)" cells are set
            // from the Topex account config (SH06570), NOT the payload — Topex
            // confirmed this — so we send no service field here.
            $orderPayload = [
                'RecipientName' => $request->input('customerName', 'Customer'),
                'TotalCOG' => (string) $this->cashToCollect($request),
                'MobileNumber' => $request->input('phone', ''),
                'ShipperRef' => $request->input('orderId', ''),
                'AddressCountry' => $this->getTopexCountryName($request->input('countryCode', 'AE')),
                'City' => $cityName,
                'Area' => $areaName,
                'Street' => $request->input('address', ''),
                'MobileNumber2' => '',
                'Remarks' => $request->input('notes', ''),
                'NumberOfPieces' => (string) $request->input('numberOfPieces', 1),
                'Weight' => (string) $weight,
                'OrderWeight' => (string) $weight,
                // "Description of goods" cell. Topex said the param is lowercase
                // `desc1`; we also send the legacy Description/GoodsDescription
                // aliases as a safety net (Topex ignores unknown keys). NOTE as of
                // 2026-06-15: none of these populate the cell yet — the description
                // mapping is still pending on Topex's side (NDD already works).
                'desc1' => $description,
                'Description' => $description,
                'GoodsDescription' => $description,
            ];

            $response = Http::withToken($token)
                ->post('http://topex-shipperapi.dispatchex.com/api/order/placebulk', [
                    'list' => [$orderPayload],
                ]);

            $data = $response->json();
            $rawBody = $response->body();

            // Bail out loudly on any non-2xx — Topex returns plain string
            // bodies like "Invalid Model Data" with HTTP 400, which the
            // old code was silently treating as a successful empty response.
            if (! $response->successful()) {
                $errMsg = is_string($data) ? $data : ($data['message'] ?? $data['error'] ?? $rawBody ?: "Topex returned HTTP {$response->status()}");
                Log::error('Topex placebulk failed', [
                    'status' => $response->status(),
                    'body' => $data ?: $rawBody,
                    'payload' => $orderPayload,
                ]);

                return response()->json([
                    'success' => false,
                    'error' => "Topex rejected the shipment: {$errMsg}",
                ], 422);
            }

            // Extract tracking number from Topex response — the API can
            // return it in different shapes depending on the endpoint version.
            $trackingNo = '';
            if (is_array($data)) {
                if (isset($data['trackingnos'][0]['tracking_no'])) {
                    $trackingNo = $data['trackingnos'][0]['tracking_no'];
                } elseif (isset($data[0]['TrackingNo'])) {
                    $trackingNo = $data[0]['TrackingNo'];
                } elseif (isset($data['TrackingNo'])) {
                    $trackingNo = $data['TrackingNo'];
                }
            }

            // No tracking number = the shipment didn't actually get created,
            // even though the HTTP call returned 2xx. Treat as failure so the
            // frontend doesn't show a fake "success" dialog.
            if (! $trackingNo) {
                Log::error('Topex returned 2xx but no tracking number', [
                    'body' => $data ?: $rawBody,
                    'payload' => $orderPayload,
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Topex did not return a tracking number. Check that City and Mobile are valid for the destination country.',
                ], 502);
            }

            $labelUrl = $this->getTopexLabel($token, $trackingNo);

            return response()->json([
                'success' => true,
                'trackingNumber' => $trackingNo,
                'labelUrl' => $labelUrl,
                'provider' => 'topex',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Topex shipment failed: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Downloads the shipping label (AWB) PDF from Topex for a tracking number.
     * Saves the PDF to local storage and returns the URL.
     */
    private function getTopexLabel(string $token, string $trackingNumber): string
    {
        try {
            $response = Http::withToken($token)
                ->get('http://topex-shipperapi.dispatchex.com/api/order/GetAirWayBill4x6', [
                    'TrackingNos' => $trackingNumber,
                ]);

            $contentType = $response->header('Content-Type');

            // If response is PDF binary, save to storage
            if (str_contains($contentType ?? '', 'pdf') || str_contains($contentType ?? '', 'octet-stream')) {
                // Labels carry the customer's name, address and phone, and
                // tracking numbers are sequential: a random suffix keeps the
                // public file unguessable (the returned URL carries it).
                $filename = 'labels/topex_'.$trackingNumber.'_'.\Illuminate\Support\Str::random(40).'.pdf';
                Storage::disk('public')->put($filename, $response->body());

                // Topex's 4x6 AWB comes back with a blank second page, which
                // wasted a sheet on every print. Best-effort: on any failure
                // the original label is left exactly as downloaded.
                if (config('services.shipping.strip_blank_label_pages', true)) {
                    app(\App\Services\PdfBlankPageStripper::class)
                        ->strip(Storage::disk('public')->path($filename));
                }

                return url('/storage/'.$filename);
            }

            // If response is JSON with a URL
            $data = $response->json();
            if (is_array($data)) {
                return $data['LabelUrl'] ?? $data['labelUrl'] ?? $data['Url'] ?? $data['url'] ?? '';
            }

            return '';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Gets tracking history for a Topex shipment.
     * Returns an array of status updates with dates and locations.
     */
    /**
     * Public wrapper around the private trackTopex/trackAramex helpers
     * so background jobs (e.g. orders:sync-delivery-status) can fetch
     * tracking data without spinning up a fake HTTP Request.
     */
    public function fetchTracking(string $trackingNumber, string $provider = 'topex'): ?array
    {
        try {
            return match ($provider) {
                'topex' => $this->trackTopex($trackingNumber),
                'aramex' => $this->trackAramex($trackingNumber),
                default => null,
            };
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Fetch live tracking events for an Aramex AWB. Returns the same
     * shape as trackTopex(): {trackingNumber, status, updates[]}.
     */
    private function trackAramex(string $trackingNumber): array
    {
        $credentials = $this->getCredentials();

        if (! $credentials['UserName']) {
            return [
                'trackingNumber' => $trackingNumber,
                'status' => 'Unknown',
                'updates' => [],
            ];
        }

        $response = Http::timeout(15)->post(
            'https://ws.aramex.net/ShippingAPI.V2/Tracking/Service_1_0.svc/json/TrackShipments',
            [
                'ClientInfo' => $credentials,
                'Shipments' => [$trackingNumber],
                'GetLastTrackingUpdateOnly' => false,
            ],
        );

        $data = $response->json();
        $tracking = $data['TrackingResults'][0] ?? null;

        $updates = collect($tracking['Value'] ?? [])
            ->filter(fn ($u) => is_array($u))
            ->map(fn ($u) => [
                'date' => $u['UpdateDateTime'] ?? '',
                'location' => $u['UpdateLocation'] ?? '',
                'description' => $u['UpdateDescription'] ?? '',
            ])
            ->values()
            ->toArray();

        return [
            'trackingNumber' => $trackingNumber,
            'status' => $updates[0]['description'] ?? 'Unknown',
            'updates' => $updates,
        ];
    }

    private function trackTopex(string $trackingNumber): array
    {
        $token = $this->getTopexToken();

        $response = Http::withToken($token)
            ->timeout(15)
            ->post('http://topex-shipperapi.dispatchex.com/api/order/GetShipmentTrackingHistory', [
                'TrackingNo' => $trackingNumber,
            ]);

        $body = $response->json();

        // Topex returns {"data": [{...}, ...]} where each event has:
        //   createdOn, trackingStatus_Name, trackingStatus_Comments, createdBy_Name
        // Unwrap "data" and fall back to other shapes for safety.
        $events = $body['data']
            ?? $body['TrackingHistory']
            ?? $body['trackingHistory']
            ?? (is_array($body) ? $body : []);

        $updates = collect($events)
            ->filter(fn ($u) => is_array($u))
            ->map(function ($u) {
                $description = trim((string) ($u['trackingStatus_Name'] ?? $u['Status'] ?? $u['StatusDescription'] ?? $u['status'] ?? ''));
                $comments = trim((string) ($u['trackingStatus_Comments'] ?? ''));
                if ($comments !== '' && $comments !== '-') {
                    $description = trim($description.' — '.$comments);
                }

                return [
                    'date' => $u['createdOn'] ?? $u['Date'] ?? $u['StatusDate'] ?? $u['date'] ?? '',
                    'location' => $u['createdBy_Name'] ?? $u['Location'] ?? $u['location'] ?? '',
                    'description' => $description,
                ];
            })
            // Most recent first — Topex returns oldest-first.
            ->reverse()
            ->values()
            ->toArray();

        return [
            'trackingNumber' => $trackingNumber,
            'status' => $updates[0]['description'] ?? 'Unknown',
            'updates' => $updates,
        ];
    }

    /**
     * Converts a 2-letter country code (like "AE") to the full name
     * that Topex expects (like "United Arab Emirates").
     */
    private function getTopexCountryName(string $code): string
    {
        $map = [
            'AE' => 'United Arab Emirates',
            'SA' => 'Saudi Arabia',
            'KW' => 'Kuwait',
            'BH' => 'Bahrain',
            'OM' => 'Oman',
            'QA' => 'Qatar',
        ];

        return $map[strtoupper($code)] ?? $code;
    }

    /**
     * Resolves a free-text city name to Topex's canonical spelling.
     * Returns null if the city list can't be fetched or no fuzzy match
     * is found — caller should fall back to the original text.
     */
    private function matchTopexCity(string $token, string $city): ?string
    {
        $needle = strtolower(trim($city));
        if ($needle === '') {
            return null;
        }

        $cities = Cache::remember('topex_cities_ae', 3600, function () use ($token) {
            $response = Http::withToken($token)->get(
                'http://topex-shipperapi.dispatchex.com/api/CommonAPI/Cities',
                ['countryID' => 1],
            );
            $data = $response->json();

            return collect($data['data'] ?? $data ?? [])
                ->map(fn ($c) => $c['name'] ?? $c['Name'] ?? '')
                ->filter()
                ->values()
                ->all();
        });

        foreach ($cities as $name) {
            if (strtolower($name) === $needle) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Resolves a free-text area name to Topex's canonical spelling for
     * the given (already-canonicalized) city. Returns null if no match.
     */
    private function matchTopexArea(string $token, string $cityName, string $area): ?string
    {
        $needle = strtolower(trim($area));
        if ($needle === '' || $cityName === '') {
            return null;
        }

        // Find the city ID first so we can fetch its areas.
        $cityId = Cache::remember('topex_city_id_'.strtolower($cityName), 3600, function () use ($token, $cityName) {
            $response = Http::withToken($token)->get(
                'http://topex-shipperapi.dispatchex.com/api/CommonAPI/Cities',
                ['countryID' => 1],
            );
            $data = $response->json();
            foreach ($data['data'] ?? $data ?? [] as $c) {
                $name = $c['name'] ?? $c['Name'] ?? '';
                if (strtolower($name) === strtolower($cityName)) {
                    return $c['city_ID'] ?? $c['ID'] ?? null;
                }
            }

            return null;
        });

        if (! $cityId) {
            return null;
        }

        $areas = Cache::remember('topex_areas_'.$cityId, 3600, function () use ($token, $cityId) {
            $response = Http::withToken($token)->get(
                'http://topex-shipperapi.dispatchex.com/api/CommonAPI/Areas',
                ['acityID' => $cityId],
            );
            $data = $response->json();

            return collect($data['data'] ?? $data ?? [])
                ->map(fn ($a) => $a['name'] ?? $a['Name'] ?? '')
                ->filter()
                ->values()
                ->all();
        });

        foreach ($areas as $name) {
            if (strtolower($name) === $needle) {
                return $name;
            }
        }

        return null;
    }

    /**
     * GET /api/shipping/topex/cities
     * Fetches the list of cities available for Topex delivery.
     */
    public function topexCities()
    {
        try {
            $token = $this->getTopexToken();
            $response = Http::withToken($token)
                ->get('http://topex-shipperapi.dispatchex.com/api/CommonAPI/Cities', [
                    'countryID' => 1,
                ]);
            $data = $response->json();
            // Transform to match frontend expected format {ID, Name}
            $cities = collect($data['data'] ?? $data)->map(fn ($c) => [
                'ID' => $c['city_ID'] ?? $c['ID'] ?? 0,
                'Name' => $c['name'] ?? $c['Name'] ?? '',
            ])->toArray();

            return response()->json($cities);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/shipping/topex/areas
     * Fetches the list of areas/neighborhoods within a Topex city.
     * Pass ?cityId=X to filter by city.
     */
    public function topexAreas(Request $request)
    {
        try {
            $token = $this->getTopexToken();
            $response = Http::withToken($token)
                ->get('http://topex-shipperapi.dispatchex.com/api/CommonAPI/Areas', [
                    'acityID' => $request->input('cityId', 1),
                ]);
            $data = $response->json();
            // Transform to match frontend expected format {ID, Name}
            $areas = collect($data['data'] ?? $data)->map(fn ($a) => [
                'ID' => $a['area_ID'] ?? $a['ID'] ?? 0,
                'Name' => $a['name'] ?? $a['Name'] ?? '',
            ])->toArray();

            return response()->json($areas);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ============ Aramex Methods ============

    /**
     * Reads Aramex API credentials from the Laravel config (services.php).
     */
    private function getCredentials(): array
    {
        return [
            'UserName' => config('services.aramex.username'),
            'Password' => config('services.aramex.password'),
            'Version' => 'v1',
            'AccountNumber' => config('services.aramex.account_number'),
            'AccountPin' => config('services.aramex.account_pin'),
            'AccountEntity' => config('services.aramex.account_entity') ?: '',
            'AccountCountryCode' => config('services.aramex.country_code') ?: Brand::countryCode(),
        ];
    }

    /**
     * Returns the sender (your store) address info for Aramex shipments.
     * Any SENDER_* value left blank in .env falls back to the Brand settings.
     */
    private function getSender(): array
    {
        $sender = fn (string $key) => (string) config("services.aramex.{$key}", '');
        $city = $sender('sender_city') ?: Brand::get('store_city_en');

        return [
            'companyName' => $sender('sender_company') ?: Brand::name(),
            'personName' => $sender('sender_person') ?: Brand::name(),
            'phone' => $sender('sender_phone') ?: Brand::phone(),
            'email' => $sender('sender_email') ?: Brand::email(),
            'addressLine1' => $sender('sender_address') ?: Brand::address(),
            'city' => $city,
            'state' => $sender('sender_state') ?: $city,
            'countryCode' => $sender('sender_country') ?: Brand::countryCode(),
        ];
    }

    /**
     * Checks if a country code is UAE (domestic shipping vs international).
     */
    private function isDomestic(string $countryCode): bool
    {
        return in_array(strtoupper($countryCode), ['AE', 'UAE']);
    }

    /**
     * POST /api/shipping/calculate-rate
     * Calculates the shipping cost using Aramex's rate API.
     * Falls back to a default rate of 25 AED if Aramex is not configured.
     */
    public function calculateRate(Request $request)
    {
        $credentials = $this->getCredentials();
        $sender = $this->getSender();

        if (! $credentials['UserName'] || ! $credentials['Password']) {
            return response()->json(['success' => true, 'rate' => 25, 'currency' => Brand::currency(), 'deliveryDays' => 3]);
        }

        $destCountry = $request->input('destinationCountry', 'AE');
        $isDomestic = $this->isDomestic($destCountry);

        $rateRequest = [
            'ClientInfo' => $credentials,
            'OriginAddress' => ['City' => $sender['city'], 'CountryCode' => $sender['countryCode']],
            'DestinationAddress' => ['City' => $request->input('destinationCity', $sender['city']), 'CountryCode' => $destCountry],
            'ShipmentDetails' => [
                'Dimensions' => ['Length' => 10, 'Width' => 10, 'Height' => 10, 'Unit' => 'CM'],
                'ActualWeight' => ['Unit' => 'KG', 'Value' => $request->input('weight', 0.5)],
                'ChargeableWeight' => ['Unit' => 'KG', 'Value' => $request->input('weight', 0.5)],
                'DescriptionOfGoods' => config('services.shipping.goods_description', 'General Merchandise'),
                'GoodsOriginCountry' => $sender['countryCode'],
                'NumberOfPieces' => $request->input('numberOfPieces', 1),
                'ProductGroup' => $isDomestic ? 'DOM' : 'EXP',
                'ProductType' => $isDomestic ? 'OND' : 'PPX',
                'PaymentType' => 'P',
            ],
        ];

        try {
            $response = Http::post('https://ws.aramex.net/ShippingAPI.V2/RateCalculator/Service_1_0.svc/json/CalculateRate', $rateRequest);
            $data = $response->json();

            if ($data['HasErrors'] ?? false) {
                throw new \Exception($data['Notifications'][0]['Message'] ?? 'Rate calculation failed');
            }

            return response()->json([
                'success' => true,
                'rate' => $data['TotalAmount']['Value'] ?? 25,
                'currency' => $data['TotalAmount']['CurrencyCode'] ?? Brand::currency(),
                'deliveryDays' => 3,
                'isDomestic' => $isDomestic,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => true, 'rate' => 25, 'currency' => Brand::currency(), 'deliveryDays' => 3]);
        }
    }

    /**
     * POST /api/shipping/create-shipment
     * Creates a real shipment with a courier (Topex or Aramex).
     * Pass provider="topex" for Topex, otherwise defaults to Aramex.
     * Returns a tracking number and shipping label URL.
     */
    public function createShipment(Request $request)
    {
        $provider = $request->input('provider', 'aramex');
        $cashToCollect = $this->cashToCollect($request);

        if ($provider === 'topex') {
            return $this->handleTopexShipment($request);
        }

        $credentials = $this->getCredentials();
        $sender = $this->getSender();

        if (! $credentials['UserName'] || ! $credentials['Password']) {
            return response()->json(['success' => false, 'message' => 'Aramex is not configured. Please add your Aramex API credentials in the .env file (ARAMEX_USERNAME, ARAMEX_PASSWORD, ARAMEX_ACCOUNT_NUMBER, ARAMEX_ACCOUNT_PIN).'], 400);
        }

        $destCountry = $request->input('countryCode', 'AE');
        $isDomestic = $this->isDomestic($destCountry);

        $shipmentRequest = [
            'ClientInfo' => $credentials,
            'LabelInfo' => ['ReportID' => 9201, 'ReportType' => 'URL'],
            'Shipments' => [[
                'Reference1' => $request->input('orderId', ''),
                'Shipper' => [
                    'Reference1' => $request->input('orderId', ''),
                    'AccountNumber' => $credentials['AccountNumber'],
                    'PartyAddress' => [
                        'Line1' => $sender['addressLine1'],
                        'City' => $sender['city'],
                        'StateOrProvinceCode' => $sender['state'],
                        'CountryCode' => $sender['countryCode'],
                    ],
                    'Contact' => [
                        'PersonName' => $sender['personName'],
                        'CompanyName' => $sender['companyName'],
                        'PhoneNumber1' => $sender['phone'],
                        'EmailAddress' => $sender['email'],
                    ],
                ],
                'Consignee' => [
                    'Reference1' => $request->input('orderId', ''),
                    'PartyAddress' => [
                        'Line1' => $request->input('address', 'Address'),
                        'Line2' => $request->input('addressExtra', ''),
                        'City' => $request->input('city', ''),
                        'StateOrProvinceCode' => $request->input('state', ''),
                        'PostCode' => $request->input('zipCode', ''),
                        'CountryCode' => $destCountry,
                    ],
                    'Contact' => [
                        'PersonName' => $request->input('customerName', 'Customer'),
                        'CompanyName' => $request->input('customerName', 'Customer'),
                        'PhoneNumber1' => $request->input('phone', '+971500000000'),
                        'EmailAddress' => $request->input('email', ''),
                    ],
                ],
                'ShippingDateTime' => '/Date('.(time() * 1000).')/',
                'DueDate' => '/Date('.((time() + 259200) * 1000).')/',
                'Comments' => $request->input('notes', ''),
                'Details' => [
                    'Dimensions' => ['Length' => 10, 'Width' => 10, 'Height' => 10, 'Unit' => 'CM'],
                    'ActualWeight' => ['Unit' => 'KG', 'Value' => $request->input('weight', 0.5)],
                    'ChargeableWeight' => ['Unit' => 'KG', 'Value' => $request->input('weight', 0.5)],
                    'DescriptionOfGoods' => config('services.shipping.goods_description', 'General Merchandise'),
                    'GoodsOriginCountry' => $sender['countryCode'],
                    'NumberOfPieces' => $request->input('numberOfPieces', 1),
                    'ProductGroup' => $isDomestic ? 'DOM' : 'EXP',
                    'ProductType' => $isDomestic ? 'OND' : 'PPX',
                    'PaymentType' => 'P',
                    'Services' => $cashToCollect > 0 ? 'CODS' : '',
                    'CashOnDeliveryAmount' => $cashToCollect > 0
                        ? ['Value' => $cashToCollect, 'CurrencyCode' => Brand::currency()]
                        : null,
                ],
            ]],
            'Transaction' => ['Reference1' => $request->input('orderId', '')],
        ];

        $response = Http::post('https://ws.aramex.net/ShippingAPI.V2/Shipping/Service_1_0.svc/json/CreateShipments', $shipmentRequest);
        $data = $response->json();

        if ($data['HasErrors'] ?? false) {
            return response()->json(['success' => false, 'message' => $data['Notifications'][0]['Message'] ?? 'Shipment failed'], 500);
        }

        $shipment = $data['Shipments'][0] ?? null;

        return response()->json([
            'success' => true,
            'trackingNumber' => $shipment['ID'] ?? '',
            'labelUrl' => $shipment['ShipmentLabel']['LabelURL'] ?? '',
            'isDomestic' => $isDomestic,
        ]);
    }

    /**
     * POST /api/shipping/track
     * Tracks a shipment using its tracking number.
     * Pass provider="topex" for Topex tracking, otherwise uses Aramex.
     * Returns the current status and a list of tracking updates.
     */
    public function track(Request $request)
    {
        $provider = $request->input('provider', 'aramex');
        $cashToCollect = $this->cashToCollect($request);
        $trackingNumber = (string) $request->input('trackingNumber', '');

        if ($trackingNumber === '') {
            return response()->json(['success' => false, 'error' => 'trackingNumber is required'], 422);
        }

        // Configuration checks before we hit either provider.
        if ($provider === 'topex' && ! $this->getTopexCredentials()['username']) {
            return response()->json(['success' => false, 'error' => 'Topex not configured'], 400);
        }
        if ($provider === 'aramex' && ! $this->getCredentials()['UserName']) {
            return response()->json(['success' => false, 'message' => 'Aramex is not configured. Please add your Aramex API credentials in the .env file.'], 400);
        }

        try {
            $result = $this->fetchTracking($trackingNumber, $provider);
            if ($result === null) {
                return response()->json(['success' => false, 'error' => "Unknown shipping provider: {$provider}"], 400);
            }

            return response()->json(array_merge(['success' => true, 'provider' => $provider], $result));
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/shipping/aramex/cities?countryCode=XX
     * Returns the list of cities Aramex recognises for a given country.
     * Used by the customer checkout City dropdown so the value we send
     * back on AWB creation is guaranteed valid for that country.
     * Cached for 24h since the list is essentially static.
     */
    public function aramexCities(Request $request)
    {
        $countryCode = strtoupper((string) $request->input('countryCode', 'AE'));
        if (strlen($countryCode) !== 2) {
            return response()->json(['error' => 'countryCode must be a 2-letter ISO code'], 422);
        }

        $cacheKey = "aramex_cities_{$countryCode}";
        $cached = cache()->get($cacheKey);
        if (is_array($cached)) {
            return response()->json($cached);
        }

        $credentials = $this->getCredentials();
        if (! $credentials['UserName'] || ! $credentials['Password']) {
            // Aramex not configured — let the frontend fall back to free-text.
            return response()->json([]);
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)->post(
                'https://ws.aramex.net/ShippingAPI.V2/Location/Service_1_0.svc/json/FetchCities',
                [
                    'ClientInfo' => $credentials,
                    'CountryCode' => $countryCode,
                    'State' => null,
                    'NameStartsWith' => null,
                    'Transaction' => ['Reference1' => 'cities-'.$countryCode],
                ]
            );
            $data = $response->json();

            if ($data['HasErrors'] ?? false) {
                return response()->json([]);
            }

            // Aramex returns { Cities: ["Abu Dhabi", "Dubai", ...] }
            $rawCities = $data['Cities'] ?? [];
            $cities = collect($rawCities)
                ->filter(fn ($c) => is_string($c) && trim($c) !== '')
                ->map(fn ($c) => ['name' => trim($c)])
                ->sortBy('name')
                ->values()
                ->all();

            cache()->put($cacheKey, $cities, now()->addHours(24));

            return response()->json($cities);
        } catch (\Exception $e) {
            // Soft-fail: empty list lets the UI fall back to free-text.
            return response()->json([]);
        }
    }
}
