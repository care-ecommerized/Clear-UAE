<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One credential of one integration (e.g. stripe.secret), stored encrypted.
 *
 * A row with a null value is an explicit "cleared" — it overrides any .env
 * fallback with null. No row at all means .env (config/services.php) wins.
 */
class IntegrationCredential extends Model
{
    use HasUuids;

    /**
     * Every provider and field the admin may edit. Each field maps to
     * config("services.<provider>.<field>"). Secret fields are never
     * returned by the API — only whether they are set, and their last 4 chars.
     *
     * A field with `allowed_hosts` (the gateways' API base URLs) only accepts
     * an https URL on one of those hosts: the secrets are sent to that host,
     * so an editable URL would otherwise leak them to anyone holding an
     * admin token (and be a server-side request forgery primitive).
     *
     * @var array<string, array{label: string, fields: array<string, array{label: string, secret: bool, allowed_hosts?: list<string>}>}>
     */
    public const PROVIDERS = [
        'stripe' => [
            'label' => 'Stripe',
            'fields' => [
                'key' => ['label' => 'Publishable key', 'secret' => false],
                'secret' => ['label' => 'Secret key', 'secret' => true],
                'webhook_secret' => ['label' => 'Webhook signing secret', 'secret' => true],
            ],
        ],
        'paymennt' => [
            'label' => 'Paymennt',
            'fields' => [
                'api_key' => ['label' => 'API key', 'secret' => true],
                'api_secret' => ['label' => 'API secret', 'secret' => true],
                'public_key' => ['label' => 'Public key', 'secret' => false],
                'webhook_secret' => ['label' => 'Webhook secret', 'secret' => true],
                'base_url' => ['label' => 'API base URL', 'secret' => false, 'allowed_hosts' => ['api.paymennt.com', 'api.test.paymennt.com']],
            ],
        ],
        'tabby' => [
            'label' => 'Tabby',
            'fields' => [
                'public_key' => ['label' => 'Public key', 'secret' => false],
                'secret_key' => ['label' => 'Secret key', 'secret' => true],
                'merchant_code' => ['label' => 'Merchant code', 'secret' => false],
                'merchant_code_sa' => ['label' => 'Merchant code (Saudi Arabia)', 'secret' => false],
                'webhook_header_name' => ['label' => 'Webhook header name', 'secret' => false],
                'webhook_header_value' => ['label' => 'Webhook header value', 'secret' => true],
                'base_url' => ['label' => 'API base URL', 'secret' => false, 'allowed_hosts' => ['api.tabby.ai']],
            ],
        ],
        'tamara' => [
            'label' => 'Tamara',
            'fields' => [
                'api_token' => ['label' => 'API token', 'secret' => true],
                'public_key' => ['label' => 'Public key', 'secret' => false],
                'notification_token' => ['label' => 'Notification token', 'secret' => true],
                'base_url' => ['label' => 'API base URL', 'secret' => false, 'allowed_hosts' => ['api.tamara.co', 'api-sandbox.tamara.co']],
            ],
        ],
        'aramex' => [
            'label' => 'Aramex',
            'fields' => [
                'username' => ['label' => 'Username', 'secret' => false],
                'password' => ['label' => 'Password', 'secret' => true],
                'account_number' => ['label' => 'Account number', 'secret' => false],
                'account_pin' => ['label' => 'Account PIN', 'secret' => true],
                'account_entity' => ['label' => 'Account entity', 'secret' => false],
                'country_code' => ['label' => 'Account country code', 'secret' => false],
            ],
        ],
        'topex' => [
            'label' => 'Topex',
            'fields' => [
                'username' => ['label' => 'Username', 'secret' => false],
                'password' => ['label' => 'Password', 'secret' => true],
                'account_number' => ['label' => 'Account number', 'secret' => false],
            ],
        ],
    ];

    /**
     * Whether $url may be used for this provider field. Fields without an
     * allowlist accept anything; the others need https on a listed host,
     * with no credentials or custom port.
     */
    public static function isAllowedUrl(string $provider, string $field, ?string $url): bool
    {
        $hosts = self::PROVIDERS[$provider]['fields'][$field]['allowed_hosts'] ?? null;
        if ($hosts === null) {
            return true;
        }

        $p = parse_url(trim((string) $url));

        return is_array($p)
            && strtolower($p['scheme'] ?? '') === 'https'
            && in_array(strtolower($p['host'] ?? ''), $hosts, true)
            && ! isset($p['user']) && ! isset($p['pass']) && ! isset($p['port']);
    }

    /** $url when allowed for the field, otherwise $default. */
    public static function safeUrl(string $provider, string $field, ?string $url, string $default): string
    {
        return ($url !== null && trim($url) !== '' && self::isAllowedUrl($provider, $field, $url)) ? trim($url) : $default;
    }

    protected $fillable = ['provider', 'field', 'value'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
        ];
    }
}
