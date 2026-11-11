<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureInstalled;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PDO;
use Throwable;

/**
 * ============================================================
 * InstallController — the "Ecomweb Installer" web wizard
 * ============================================================
 * For buyers on shared hosting without SSH. Steps:
 *   1. requirements  PHP version, extensions, writable folders
 *   2. database      MySQL or SQLite; tests the connection, writes .env
 *   3. migrate       migrate + base seed (+ optional demo catalogue)
 *   4. admin         first admin account
 *   5. store         store name / email / currency → site_settings
 *   6. finish        writes storage/installed, shows the cron line
 *
 * Only reachable while the store is not installed (EnsureInstalled
 * 404s every /install URL afterwards). Step 2 asks for the install key
 * from backend/storage/install-key.php (proof of access to the hosting
 * files), so a stranger who finds the freshly uploaded installer cannot
 * claim the store or use the connection test to probe the network. From
 * step 2 on, the browser that wrote .env is the only one allowed to
 * continue: a random token is kept both in its session and in
 * storage/framework/installer.json.
 * ============================================================
 */
class InstallController extends Controller
{
    public const STEPS = [
        'requirements' => 'Requirements',
        'database' => 'Database',
        'migrate' => 'Install',
        'admin' => 'Admin account',
        'store' => 'Store basics',
        'finish' => 'Finish',
    ];

    private const EXTENSIONS = ['pdo', 'mbstring', 'openssl', 'ctype', 'tokenizer', 'session', 'filter', 'gd', 'intl', 'zip', 'fileinfo', 'curl'];

    private const CURRENCIES = ['AED', 'SAR', 'KWD', 'QAR', 'BHD', 'OMR', 'EGP', 'JOD', 'USD', 'EUR', 'GBP', 'INR', 'PKR', 'TRY', 'MAD'];

    /**
     * Rate limiter for install-key attempts. The app's configured cache is the
     * database store, which doesn't exist until step 3 has migrated, so the
     * wizard keeps its counters in the file cache (array cache under tests).
     */
    private function keyLimiter(): \Illuminate\Cache\RateLimiter
    {
        $store = app()->environment('testing') ? 'array' : 'file';

        return new \Illuminate\Cache\RateLimiter(\Illuminate\Support\Facades\Cache::store($store));
    }

    // ---------------------------------------------------------------
    // Entry: send the visitor to the step they are on.
    // ---------------------------------------------------------------

    public function index(Request $request): RedirectResponse
    {
        if (! $this->requirementsPass()) {
            return redirect('/install/requirements');
        }
        if (! $this->envWritten() || ! $this->ownsInstall($request)) {
            return redirect('/install/database');
        }
        if (! $this->migrated()) {
            return redirect('/install/migrate');
        }
        if (! $this->hasAdmin()) {
            return redirect('/install/admin');
        }

        return redirect('/install/store');
    }

    // ---------------------------------------------------------------
    // 1. Requirements
    // ---------------------------------------------------------------

    public function requirements(): View
    {
        $checks = $this->requirementChecks();

        return $this->page('requirements', [
            'checks' => $checks,
            'passes' => ! in_array(false, array_column($checks, 'ok'), true),
        ]);
    }

    // ---------------------------------------------------------------
    // 2. Database
    // ---------------------------------------------------------------

    public function database(Request $request): View|RedirectResponse
    {
        if (! $this->requirementsPass()) {
            return redirect('/install/requirements');
        }
        if ($blocked = $this->blockedByOtherBrowser($request)) {
            return $blocked;
        }

        EnsureInstalled::installKey(); // make sure the key file exists before the owner looks for it

        return $this->page('database', [
            'sqliteAvailable' => extension_loaded('pdo_sqlite'),
            'mysqlAvailable' => extension_loaded('pdo_mysql'),
            'sqlitePath' => $this->sqlitePath(),
            'keyFile' => 'backend/storage/install-key.php',
            'requireInstallKey' => $this->requiresInstallKey($request),
            'siteUrl' => $this->suggestedSiteUrl($request),
        ]);
    }

    public function saveDatabase(Request $request): RedirectResponse
    {
        if (! $this->requirementsPass()) {
            return redirect('/install/requirements');
        }
        if ($blocked = $this->blockedByOtherBrowser($request)) {
            return $blocked;
        }

        $requireKey = $this->requiresInstallKey($request);

        $data = $request->validate([
            'driver' => 'required|in:mysql,sqlite',
            'host' => 'required_if:driver,mysql|nullable|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'database' => 'required_if:driver,mysql|nullable|string|max:255',
            'username' => 'required_if:driver,mysql|nullable|string|max:255',
            'password' => 'nullable|string|max:255',
            'install_key' => ($requireKey ? 'required' : 'nullable').'|string|max:255',
            'site_url' => 'nullable|string|max:255',
        ]);

        // Proof of access to the hosting files — checked before anything
        // touches the network (connection test) or the disk (.env).
        if ($requireKey) {
            $limiterKey = 'installer-key:'.$request->ip();
            if ($this->keyLimiter()->tooManyAttempts($limiterKey, 5)) {
                return back()->withInput($request->except('password', 'install_key'))
                    ->withErrors(['install_key' => 'Too many attempts. Wait a minute and try again.']);
            }
            $expected = EnsureInstalled::installKey();
            if ($expected === '' || ! hash_equals($expected, strtoupper(trim((string) $data['install_key'])))) {
                $this->keyLimiter()->hit($limiterKey, 60);

                return back()->withInput($request->except('password', 'install_key'))
                    ->withErrors(['install_key' => 'Wrong install key. Open backend/storage/install-key.php in your hosting File Manager and copy the code on its second line.']);
            }
            $this->keyLimiter()->clear($limiterKey);
        }

        $siteUrl = $this->normaliseSiteUrl((string) ($data['site_url'] ?? ''));
        if ($siteUrl === false) {
            return back()->withInput($request->except('password', 'install_key'))
                ->withErrors(['site_url' => 'Enter the full store address starting with https:// (http:// is only allowed for localhost).']);
        }
        if ($siteUrl !== null) {
            $request->session()->put('installer_app_url', $siteUrl);
        }

        $db = $data['driver'] === 'sqlite'
            ? ['connection' => 'sqlite', 'database' => $this->sqlitePath()]
            : [
                'connection' => 'mysql',
                'host' => trim((string) $data['host']),
                'port' => (string) ($data['port'] ?: 3306),
                'database' => trim((string) $data['database']),
                'username' => trim((string) $data['username']),
                'password' => (string) ($data['password'] ?? ''),
            ];

        if ($error = $this->testConnection($db)) {
            return back()->withInput($request->except('password', 'install_key'))
                ->withErrors(['connection' => 'Could not connect to the database: '.$error]);
        }

        // From here on, only this browser may continue the install. The token
        // and the "install in progress" marker are written BEFORE .env: a
        // .env without them would make the half-installed store look
        // installed (EnsureInstalled::detectExistingInstall).
        $state = EnsureInstalled::state();
        $state['token'] = $state['token'] ?? Str::random(40);
        unset($state['migrated']);
        if (! EnsureInstalled::saveState($state)
            || @file_put_contents(EnsureInstalled::inProgressPath(), now()->toIso8601String()) === false) {
            return back()->withInput($request->except('password', 'install_key'))
                ->withErrors(['connection' => 'Could not write to backend/storage — make sure it (and storage/framework) is writable.']);
        }
        $request->session()->put('installer_token', $state['token']);

        try {
            $this->writeEnv($request, $db);
        } catch (Throwable $e) {
            return back()->withInput($request->except('password', 'install_key'))
                ->withErrors(['connection' => 'Could not write the .env file: '.$e->getMessage()]);
        }

        $this->useDatabase($db);

        return redirect('/install/migrate');
    }

    // ---------------------------------------------------------------
    // 3. Migrate + seed
    // ---------------------------------------------------------------

    public function migrate(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guard($request, 'migrate')) {
            return $redirect;
        }

        return $this->page('migrate', [
            'migrated' => $this->migrated(),
            'demoAvailable' => class_exists('Database\\Seeders\\DemoSeeder'),
        ]);
    }

    public function runMigrate(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guard($request, 'migrate')) {
            return $redirect;
        }

        @set_time_limit(600);
        $output = '';

        // Cleared before any work and set only once everything succeeded, so
        // a run that dies half-way (time limit, proxy timeout) is never
        // mistaken for a finished one.
        $state = EnsureInstalled::state();
        unset($state['migrated']);
        EnsureInstalled::saveState($state);

        try {
            Artisan::call('migrate', ['--force' => true]);
            $output .= Artisan::output();

            // Before seeding, so demo photos are reachable as soon as they exist.
            $this->linkStorage();

            Artisan::call('db:seed', ['--force' => true]);
            $output .= Artisan::output();

            if ($request->boolean('demo') && class_exists('Database\\Seeders\\DemoSeeder')) {
                // Demo image URLs are stored absolute: build them on the
                // confirmed store URL, not on this request's host/scheme.
                $appUrl = $this->appUrl($request);
                URL::forceRootUrl($appUrl);
                if (str_starts_with($appUrl, 'https://')) {
                    URL::forceScheme('https');
                }
                Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\DemoSeeder', '--force' => true]);
                $output .= Artisan::output();
            }
        } catch (Throwable $e) {
            return $this->page('migrate', [
                'migrated' => false,
                'demoAvailable' => class_exists('Database\\Seeders\\DemoSeeder'),
                'failure' => $e->getMessage(),
                'output' => trim($output),
            ]);
        }

        $state = EnsureInstalled::state();
        $state['migrated'] = true;
        if (! EnsureInstalled::saveState($state)) {
            return $this->page('migrate', [
                'migrated' => false,
                'demoAvailable' => class_exists('Database\\Seeders\\DemoSeeder'),
                'failure' => 'Could not write backend/storage/framework/installer.json — make sure backend/storage is writable.',
                'output' => trim($output),
            ]);
        }

        return redirect('/install/admin');
    }

    // ---------------------------------------------------------------
    // 4. Admin account
    // ---------------------------------------------------------------

    public function admin(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guard($request, 'admin')) {
            return $redirect;
        }

        return $this->page('admin');
    }

    public function saveAdmin(Request $request): RedirectResponse
    {
        if ($redirect = $this->guard($request, 'admin')) {
            return $redirect;
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:8|confirmed',
        ]);

        // Same as `php artisan admin:create`: create the user (or reuse an
        // existing one with that email) and grant the admin role.
        $email = strtolower(trim($data['email']));
        $user = User::where('email', $email)->first();

        if (! $user) {
            $user = User::create([
                'email' => $email,
                'name' => $data['name'],
                'password' => Hash::make($data['password']),
            ]);
        } else {
            $user->forceFill(['name' => $data['name'], 'password' => Hash::make($data['password'])])->save();
        }

        if (! $user->isAdmin()) {
            UserRole::create(['user_id' => $user->id, 'role' => 'admin']);
        }

        $request->session()->put('installer_admin_email', $email);

        return redirect('/install/store');
    }

    // ---------------------------------------------------------------
    // 5. Store basics → 6. Finish
    // ---------------------------------------------------------------

    public function store(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guard($request, 'store')) {
            return $redirect;
        }

        return $this->page('store', [
            'currencies' => self::CURRENCIES,
            'defaults' => [
                'store_name' => $this->setting('STORE_NAME', 'Ecomweb'),
                'store_email' => $this->setting('STORE_EMAIL', (string) $request->session()->get('installer_admin_email', '')),
                'default_currency' => $this->setting('DEFAULT_CURRENCY', 'AED'),
            ],
        ]);
    }

    public function saveStore(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guard($request, 'store')) {
            return $redirect;
        }

        $data = $request->validate([
            'store_name' => 'required|string|max:120',
            'store_email' => 'required|email|max:255',
            'default_currency' => 'required|string|size:3|in:'.implode(',', self::CURRENCIES),
        ]);

        foreach ([
            'STORE_NAME' => trim($data['store_name']),
            'STORE_EMAIL' => strtolower(trim($data['store_email'])),
            'DEFAULT_CURRENCY' => strtoupper($data['default_currency']),
        ] as $key => $value) {
            SiteSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        if (! EnsureInstalled::markInstalled()) {
            return back()->withInput()->withErrors([
                'store_name' => 'Could not write storage/installed — make sure backend/storage is writable.',
            ]);
        }

        // The settings saves above already flush the Brand cache; once more
        // explicitly so the real (database) cache store never keeps the
        // pre-install defaults.
        Brand::flush();

        try {
            Artisan::call('config:clear');
        } catch (Throwable) {
            // Nothing cached — fine.
        }

        $request->session()->forget(['installer_token', 'installer_admin_email', 'installer_app_url']);
        $url = $this->appUrl($request);

        // storage/installed now exists, so /install/* is a 404 from here on:
        // render the finish page directly instead of redirecting to it.
        return $this->page('finish', [
            'storeUrl' => $url.'/',
            'adminUrl' => $url.'/admin',
            'cron' => $this->cronLine(),
            'storeName' => $data['store_name'],
        ]);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function page(string $step, array $data = []): View
    {
        return view('install.'.$step, $data + [
            'steps' => self::STEPS,
            'current' => $step,
        ]);
    }

    /**
     * Each later step needs the steps before it done, and must come from the
     * browser that started the install.
     */
    private function guard(Request $request, string $step): ?RedirectResponse
    {
        if (! $this->envWritten()) {
            return redirect('/install/database');
        }
        if (! $this->ownsInstall($request)) {
            return redirect('/install/database');
        }
        if (in_array($step, ['admin', 'store'], true) && ! $this->migrated()) {
            return redirect('/install/migrate');
        }
        if ($step === 'store' && ! $this->hasAdmin()) {
            return redirect('/install/admin');
        }

        return null;
    }

    /** A second browser may not take over an install that another one started. */
    private function blockedByOtherBrowser(Request $request): ?RedirectResponse
    {
        if (! empty(EnsureInstalled::state()['token']) && ! $this->ownsInstall($request)) {
            if ($request->isMethod('get')) {
                // Shown on the page instead of redirecting in a loop.
                session()->now('locked', true);

                return null;
            }

            return back()->withErrors(['connection' => $this->lockedMessage()]);
        }

        return null;
    }

    private function lockedMessage(): string
    {
        return 'An installation was already started from another browser. Continue it there, or delete '
            .'backend/storage/framework/installer.json in your hosting file manager to restart the wizard '
            .'from the Database step (an existing database and admin account are kept). If the store finished '
            .'installing without you, reset it by deleting backend/storage/installed, backend/.env and installer.json.';
    }

    /**
     * Whether step 2 asks for the install key. On once the Database form
     * has the field; config('installer.require_key') overrides. A browser
     * that already holds the install token is not asked again.
     */
    private function requiresInstallKey(Request $request): bool
    {
        if ($this->ownsInstall($request)) {
            return false;
        }

        $configured = config('installer.require_key');
        if ($configured !== null) {
            return (bool) $configured;
        }

        $view = resource_path('views/install/database.blade.php');

        return is_file($view) && str_contains((string) @file_get_contents($view), 'install_key');
    }

    /** https://<this host>, or this request's scheme on a local host. */
    private function suggestedSiteUrl(Request $request): string
    {
        $host = $request->getHttpHost();

        return ($this->isLocalHost($host) ? $this->detectedScheme($request) : 'https').'://'.$host;
    }

    private function isLocalHost(string $host): bool
    {
        $host = strtolower((string) preg_replace('/:\d+$/', '', $host));

        return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || str_ends_with($host, '.test');
    }

    /**
     * The store URL the buyer confirmed: null when left blank (keep
     * auto-detection), false when invalid, otherwise scheme://host[/path]
     * without a trailing slash or a /backend(/public) suffix.
     */
    private function normaliseSiteUrl(string $url): string|false|null
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        if ($scheme === 'http' && ! $this->isLocalHost($host)) {
            return false;
        }

        $path = (string) preg_replace('#/backend(/public)?$#i', '', rtrim((string) ($parts['path'] ?? ''), '/'));

        return $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '').rtrim($path, '/');
    }

    /**
     * The cron line for the finish page. Uses the PHP binary matching the
     * version serving this request when a known cPanel/CloudLinux path
     * exists (plain `php` in cron is the server default, which may be
     * older), and logs to storage/logs/cron.log instead of /dev/null so a
     * failing scheduler is visible.
     */
    private function cronLine(): string
    {
        $v = PHP_MAJOR_VERSION.PHP_MINOR_VERSION;
        $php = 'php';
        foreach (["/usr/local/bin/ea-php{$v}", "/opt/cpanel/ea-php{$v}/root/usr/bin/php", "/opt/alt/php{$v}/usr/bin/php"] as $bin) {
            if (@is_file($bin) && @is_executable($bin)) {
                $php = $bin;
                break;
            }
        }

        return '* * * * * '.$php.' '.escapeshellarg(base_path('artisan'))
            .' schedule:run >> '.escapeshellarg(storage_path('logs/cron.log')).' 2>&1';
    }

    /**
     * Publish backend/storage/app/public (uploaded images) at /storage.
     *
     * On the shipped layout the domain serves the folder ABOVE backend/
     * (public_html/), so the link that matters is public_html/storage — not
     * backend/public/storage, which is all `php artisan storage:link` makes.
     * Both are created when missing. Best effort: hosts that disable
     * symlink() still work through the /storage/{path} fallback route in
     * routes/web.php, just a little slower.
     */
    private function linkStorage(): void
    {
        if (app()->runningUnitTests()) {
            return; // never drop links into the developer's checkout
        }

        $target = storage_path('app/public');
        $links = [public_path('storage')];

        $docroot = dirname(base_path());
        if (is_file($docroot.'/index.html') || is_file($docroot.'/.htaccess')) {
            $links[] = $docroot.'/storage';
        }

        foreach ($links as $link) {
            if (file_exists($link) || is_link($link) || ! function_exists('symlink')) {
                continue;
            }
            try {
                @symlink($target, $link);
            } catch (Throwable) {
                // Fallback route covers it.
            }
        }
    }

    private function ownsInstall(Request $request): bool
    {
        $token = EnsureInstalled::state()['token'] ?? null;

        return $token && hash_equals($token, (string) $request->session()->get('installer_token', ''));
    }

    private function envWritten(): bool
    {
        return is_file(EnsureInstalled::envPath()) && ! empty(EnsureInstalled::state()['token']);
    }

    /**
     * Migrate + seed finished in full: the state flag (set only after the
     * seeders succeeded) and no pending migration files.
     */
    private function migrated(): bool
    {
        if (empty(EnsureInstalled::state()['migrated'])) {
            return false;
        }

        try {
            if (! Schema::hasTable('migrations')) {
                return false;
            }
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
            $ran = $migrator->getRepository()->getRan();

            return array_diff(array_keys($files), $ran) === [];
        } catch (Throwable) {
            return false;
        }
    }

    private function hasAdmin(): bool
    {
        try {
            return DB::table('user_roles')->where('role', 'admin')->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function setting(string $key, string $fallback): string
    {
        try {
            $value = SiteSetting::where('key', $key)->value('value');
        } catch (Throwable) {
            $value = null;
        }

        return ($value === null || $value === '') ? $fallback : (string) $value;
    }

    private function sqlitePath(): string
    {
        return (string) config('installer.sqlite_path', database_path('database.sqlite'));
    }

    /** @return array<int, array{label: string, value: string, ok: bool}> */
    private function requirementChecks(): array
    {
        $checks = [[
            'label' => 'PHP 8.2 or newer',
            'value' => PHP_VERSION,
            'ok' => version_compare(PHP_VERSION, '8.2.0', '>='),
        ]];

        foreach (self::EXTENSIONS as $ext) {
            $loaded = extension_loaded($ext);
            $checks[] = ['label' => "PHP extension: {$ext}", 'value' => $loaded ? 'Enabled' : 'Missing', 'ok' => $loaded];
        }

        $mysql = extension_loaded('pdo_mysql');
        $sqlite = extension_loaded('pdo_sqlite');
        $checks[] = [
            'label' => 'PHP extension: pdo_mysql or pdo_sqlite',
            'value' => implode(', ', array_filter([$mysql ? 'pdo_mysql' : null, $sqlite ? 'pdo_sqlite' : null])) ?: 'Missing',
            'ok' => $mysql || $sqlite,
        ];

        foreach ([
            'backend/storage' => storage_path(),
            'backend/storage/framework' => storage_path('framework'),
            'backend/storage/logs' => storage_path('logs'),
            'backend/bootstrap/cache' => base_path('bootstrap/cache'),
        ] as $label => $dir) {
            $ok = is_dir($dir) && is_writable($dir);
            $checks[] = ['label' => "Writable: {$label}", 'value' => $ok ? 'Writable' : 'Not writable', 'ok' => $ok];
        }

        $env = EnsureInstalled::envPath();
        $envOk = is_file($env) ? is_writable($env) : is_writable(dirname($env));
        $checks[] = [
            'label' => is_file($env) ? 'Writable: backend/.env' : 'Writable: backend/ (to create .env)',
            'value' => $envOk ? 'Writable' : 'Not writable',
            'ok' => $envOk,
        ];

        $example = $this->envExamplePath();
        $checks[] = ['label' => 'backend/.env.example present', 'value' => is_file($example) ? 'Found' : 'Missing', 'ok' => is_file($example)];

        return $checks;
    }

    private function requirementsPass(): bool
    {
        return ! in_array(false, array_column($this->requirementChecks(), 'ok'), true);
    }

    private function envExamplePath(): string
    {
        return (string) config('installer.env_example_path', base_path('.env.example'));
    }

    /** Returns null when the connection works, otherwise the error message. */
    private function testConnection(array $db): ?string
    {
        try {
            if ($db['connection'] === 'sqlite') {
                if (! extension_loaded('pdo_sqlite')) {
                    return 'the pdo_sqlite extension is not enabled.';
                }
                $path = $db['database'];
                if (! is_file($path)) {
                    if (! is_dir(dirname($path)) || ! @touch($path)) {
                        return "could not create {$path} — make sure backend/database is writable.";
                    }
                }
                if (! is_writable($path) || ! is_writable(dirname($path))) {
                    return "{$path} (and its folder) must be writable.";
                }
                new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

                return null;
            }

            if (! extension_loaded('pdo_mysql')) {
                return 'the pdo_mysql extension is not enabled.';
            }

            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']);
            $pdo = new PDO($dsn, $db['username'], $db['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            // The migrations use expression defaults (UUID()), which MySQL
            // only supports from 8.0.13 (MariaDB from 10.2).
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            if (! str_contains(strtolower($version), 'mariadb')
                && version_compare(preg_replace('/[^0-9.].*$/', '', $version) ?: '0', '8.0.13', '<')) {
                return "MySQL 8.0.13 or later is required (this server runs {$version}). Ask your host for a newer MySQL, or use MariaDB 10.3+.";
            }

            return null;
        } catch (Throwable $e) {
            if ($db['connection'] === 'sqlite') {
                return $e->getMessage(); // local paths only
            }

            // Never echo the raw driver text (it describes remote hosts and
            // ports): the error code plus a hint for common cPanel mistakes.
            $code = $e instanceof \PDOException ? ((int) ($e->errorInfo[1] ?? 0) ?: (int) $e->getCode()) : 0;
            $hint = match ($code) {
                1045 => 'check the username and password, including the cPanel account prefix (cpuser_).',
                1044 => 'in cPanel → MySQL Databases, use "Add User To Database" with ALL PRIVILEGES.',
                1049 => 'the database name needs the cPanel account prefix (cpuser_shop).',
                2002 => 'the server was not reachable; on cPanel use "localhost" as the host.',
                default => 'check the host, database name, username and password.',
            };

            return 'error '.$code.' — '.$hint;
        }
    }

    /** Point the running request at the new database (the next request reads .env). */
    private function useDatabase(array $db): void
    {
        $name = $db['connection'];
        $config = ['database' => $db['database']];
        if ($name === 'mysql') {
            $config += [
                'host' => $db['host'],
                'port' => $db['port'],
                'username' => $db['username'],
                'password' => $db['password'],
            ];
        }

        foreach ($config as $key => $value) {
            config(["database.connections.{$name}.{$key}" => $value]);
        }
        config(['database.default' => $name]);
        DB::purge($name);
    }

    /**
     * The URL the store is served from (never the /backend folder): the one
     * the buyer confirmed at step 2, otherwise scheme + host of this request.
     */
    private function appUrl(Request $request): string
    {
        $confirmed = (string) $request->session()->get('installer_app_url', '');

        return rtrim($confirmed !== '' ? $confirmed : $this->detectedScheme($request).'://'.$request->getHttpHost(), '/');
    }

    private function detectedScheme(Request $request): string
    {
        return strtolower((string) $request->header('X-Forwarded-Proto', '')) === 'https' || $request->isSecure()
            ? 'https'
            : 'http';
    }

    /** Build backend/.env from .env.example with the install's values. */
    private function writeEnv(Request $request, array $db): void
    {
        $template = (string) @file_get_contents($this->envExamplePath());
        if ($template === '') {
            throw new \RuntimeException('backend/.env.example is missing or empty.');
        }

        $appUrl = $this->appUrl($request);

        $values = [
            'APP_NAME' => 'Ecomweb',
            'APP_ENV' => 'production',
            'APP_KEY' => (string) config('app.key'),
            'APP_DEBUG' => 'false',
            'APP_URL' => $appUrl,
            'FRONTEND_URL' => $appUrl,
            'LOG_LEVEL' => 'error',
            'DB_CONNECTION' => $db['connection'],
            'DB_HOST' => $db['host'] ?? '',
            'DB_PORT' => $db['port'] ?? '',
            'DB_DATABASE' => $db['database'],
            'DB_USERNAME' => $db['username'] ?? '',
            'DB_PASSWORD' => $db['password'] ?? '',
            'QUEUE_CONNECTION' => 'database',
            'CACHE_STORE' => 'database',
            'MAIL_MAILER' => 'log',
            'MAIL_LOG_CHANNEL' => 'mail',
        ];

        foreach ($values as $key => $value) {
            $line = $key.'='.$this->envValue((string) $value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            $count = 0;
            $template = preg_replace_callback($pattern, fn () => $line, $template, 1, $count);
            if ($count === 0) {
                $template = rtrim($template)."\n".$line."\n";
            }
        }

        if (@file_put_contents(EnsureInstalled::envPath(), $template) === false) {
            throw new \RuntimeException('permission denied for '.EnsureInstalled::envPath());
        }

        try {
            Artisan::call('config:clear');
        } catch (Throwable) {
            // No cached config — nothing to clear.
        }
    }

    /** Quote a value so phpdotenv reads it back verbatim. */
    private function envValue(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_.:\/@+=,-]+$/', $value)) {
            return $value;
        }

        if (! str_contains($value, "'")) {
            return "'".$value."'"; // single quotes: no escapes, no ${} interpolation
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
