<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * ============================================================
 * EnsureInstalled — gate the store behind the web installer
 * ============================================================
 * Buyers on shared hosting install through /install (no SSH).
 * Until storage/installed exists:
 *   - API requests get 503 {"install_required": true, "install_url": "/install"}
 *     (the SPA's api.ts turns that into a redirect),
 *   - web requests are redirected to /install,
 *   - the request runs on file session/cache (the database is not
 *     configured yet) and, while there is no APP_KEY, on a temporary
 *     key kept in storage/framework/installer.json, so encrypted
 *     cookies and CSRF work from the very first request.
 * Once installed, every /install URL is a 404.
 *
 * A store with a .env holding an APP_KEY, no installer run in progress
 * and no storage/framework/installing marker is ALWAYS treated as
 * installed (and the storage/installed marker is recreated) — the database
 * is never consulted, so a DB outage or a restore without the marker can
 * never reopen /install on a live store. To re-run the installer, delete
 * backend/.env (together with storage/installed).
 *
 * Taking the install over (step 2) needs the install key kept in
 * storage/install-key.php: proof that the browser controls the hosting
 * files. That file is a PHP script, so a direct web request for it runs
 * the script and gets an empty 404, never the key.
 *
 * Skipped in the testing environment unless a test opts in with
 * config(['installer.enforce_in_testing' => true]).
 *
 * Paths are overridable via config('installer.*') so tests never touch
 * the real backend/.env or storage/installed.
 * ============================================================
 */
class EnsureInstalled
{
    public const VERSION = '1.0.0';

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::enforced()) {
            return $next($request);
        }

        $isInstallRoute = $request->is('install') || $request->is('install/*');

        if (self::installed()) {
            if ($isInstallRoute) {
                abort(404);
            }

            return $next($request);
        }

        self::prepareRuntime();

        if ($isInstallRoute || $request->is('up')) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'install_required' => true,
                'install_url' => '/install',
            ], 503);
        }

        return redirect('/install');
    }

    /** False in the testing environment, unless a test opts in. */
    public static function enforced(): bool
    {
        if (app()->environment('testing')) {
            return (bool) config('installer.enforce_in_testing', false);
        }

        return true;
    }

    public static function markerPath(): string
    {
        return (string) config('installer.marker_path', storage_path('installed'));
    }

    public static function envPath(): string
    {
        return (string) config('installer.env_path', base_path('.env'));
    }

    /** Installer working state: the temporary APP_KEY and the session token. */
    public static function statePath(): string
    {
        return (string) config('installer.state_path', storage_path('framework/installer.json'));
    }

    /**
     * Written by the installer just before it creates .env and removed by
     * markInstalled(): "this .env belongs to an unfinished install".
     */
    public static function inProgressPath(): string
    {
        return (string) config('installer.in_progress_path', storage_path('framework/installing'));
    }

    /** The per-site install key (second line of a PHP file that 404s when requested). */
    public static function keyPath(): string
    {
        return (string) config('installer.key_path', storage_path('install-key.php'));
    }

    /** Read the install key, creating it on first use. Empty when storage is unwritable. */
    public static function installKey(): string
    {
        $path = self::keyPath();
        if (! is_file($path)) {
            if (! is_dir(dirname($path))) {
                @mkdir(dirname($path), 0755, true);
            }
            @file_put_contents($path, "<?php http_response_code(404); exit; ?>\n".Str::upper(Str::random(20))."\n");
        }

        $lines = preg_split('/\R/', (string) @file_get_contents($path));

        return trim((string) ($lines[1] ?? ''));
    }

    public static function installed(): bool
    {
        if (is_file(self::markerPath())) {
            return true;
        }

        return self::detectExistingInstall();
    }

    /** @return array{key?: string, token?: string} */
    public static function state(): array
    {
        $path = self::statePath();
        if (! is_file($path)) {
            return [];
        }

        $data = json_decode((string) @file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    public static function saveState(array $state): bool
    {
        $path = self::statePath();
        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0755, true);
        }

        return @file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT)) !== false;
    }

    /** Write storage/installed and drop the installer state. */
    public static function markInstalled(bool $legacy = false): bool
    {
        $payload = [
            'installed_at' => now()->toIso8601String(),
            'version' => self::VERSION,
        ];
        if ($legacy) {
            $payload['detected'] = 'existing-install';
        }

        $written = @file_put_contents(self::markerPath(), json_encode($payload, JSON_PRETTY_PRINT)) !== false;

        if ($written) {
            foreach ([self::statePath(), self::inProgressPath(), self::keyPath()] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }

        return $written;
    }

    /**
     * Before install the database is not configured, so nothing may depend
     * on it: sessions and cache move to files, and a missing APP_KEY is
     * replaced by a temporary one that survives between requests (and later
     * becomes the real APP_KEY when the installer writes .env).
     */
    public static function prepareRuntime(): void
    {
        $sessionsDir = storage_path('framework/sessions');
        $filesWritable = is_dir($sessionsDir) && is_writable($sessionsDir);

        if (in_array(config('session.driver'), ['database', 'redis', 'memcached', 'dynamodb'], true)) {
            // Unwritable storage: still render the requirements page (which
            // reports the problem) instead of crashing on session save.
            config(['session.driver' => $filesWritable ? 'file' : 'array']);
        }

        if (in_array(config('cache.default'), ['database', 'redis', 'memcached', 'dynamodb'], true)) {
            // Remembered so Brand::flush() can also clear the store the app
            // will use once installed (values cached there before the swap
            // would otherwise outlive the installer's changes).
            config(['installer.real_cache_store' => config('cache.default')]);
            config(['cache.default' => $filesWritable ? 'file' : 'array']);
        }

        if (empty(config('app.key'))) {
            $state = self::state();
            if (empty($state['key'])) {
                $state['key'] = 'base64:'.base64_encode(random_bytes(32));
                self::saveState($state);
            }

            config(['app.key' => $state['key']]);
            app()->forgetInstance('encrypter');
        }
    }

    /**
     * No marker: a .env with an APP_KEY means an existing store (one that
     * predates the installer, or was restored/updated without
     * storage/installed) unless the installer itself is mid-run. The database
     * is deliberately NOT consulted — a DB outage must never reopen /install.
     */
    private static function detectExistingInstall(): bool
    {
        if (is_file(self::inProgressPath())) {
            return false; // the installer wrote this .env and has not finished
        }

        if (! empty(self::state()['token'])) {
            return false; // an installer run is in progress
        }

        if (! is_file(self::envPath()) || empty(config('app.key'))) {
            return false; // fresh package: only .env.example is shipped
        }

        // Best effort: the answer is the same if storage is read-only.
        self::markInstalled(legacy: true);

        return true;
    }
}
