<?php

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Client;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Module\ModuleRegistry;
use Illuminate\Support\Facades\Http;
use Modules\Servers\CPanelPro\CPanelProModule;
use Modules\Servers\CPanelPro\CPanelProServiceProvider;

const WHM = 'https://whm.example.test:2087/json-api/';

function cpServer(array $over = []): Server
{
    return Server::factory()->create(array_merge([
        'name' => 'WHM 1', 'hostname' => 'whm.example.test', 'ip_address' => '', 'type' => 'cpanelpro',
        'port' => 2087, 'username' => 'root', 'access_hash' => 'TOKEN123', 'active' => true,
    ], $over));
}

/** @return array{0: User, 1: Service} */
function cpService(?Server $server = null, array $over = []): array
{
    $server ??= cpServer();
    $user = User::factory()->create();
    $client = Client::factory()->create();
    $user->clients()->attach($client->id);
    $product = Product::factory()->create(['server_type' => 'cpanelpro']);
    $service = Service::factory()->create(array_merge([
        'client_id' => $client->id, 'product_id' => $product->id, 'server_id' => $server->id,
        'username' => 'asha123', 'domain' => 'asha.example.com', 'status' => 'Active',
    ], $over));

    return [$user, $service];
}

function cpFakeWhm(array $extra = []): void
{
    Http::fake($extra + [
        WHM.'create_user_session' => fn ($r) => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => ['url' => 'https://whm.example.test:'.($r['service'] === 'whostmgrd' ? '2087' : '2083').'/cpsess123/login/?session='.$r['user'].':abc', 'expires' => 9999999999]]),
        WHM.'accountsummary' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => [['user' => 'asha123', 'diskused' => '1536M', 'disklimit' => '10240M']]]]),
        WHM.'showbw' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => [['user' => 'asha123', 'totalbytes' => 524288000, 'limit' => 104857600000]]]]),
        WHM.'uapi_cpanel' => fn ($r) => Http::response(['metadata' => ['result' => 1], 'data' => ['uapi' => $r['cpanel.module'] === 'StatsBar'
            ? ['status' => 1, 'data' => [['name' => 'emailaccounts', '_count' => 4], ['name' => 'sqldatabases', '_count' => 2], ['name' => 'ftpaccounts', '_count' => 1], ['name' => 'addondomains', '_count' => 1], ['name' => 'subdomains', '_count' => 3]]]
            : ['status' => 1, 'data' => ['main_domain' => 'asha.example.com', 'addon_domains' => ['shop.example.net'], 'parked_domains' => [], 'sub_domains' => ['blog.asha.example.com']]]]]),
    ]);
}

function cpAdmin(bool $full = true): Admin
{
    return Admin::factory()->create(['role_id' => ($full ? AdminRole::factory()->fullAdmin() : AdminRole::factory())->create()->id]);
}

beforeEach(function () {
    if (! Route::has('cpanelpro.admin.whm')) {
        app()->register(CPanelProServiceProvider::class);
        app('router')->getRoutes()->refreshNameLookups();
    }
    include base_path('modules/Servers/CPanelPro/hooks.php');
});

test('the module is discovered as its own server type and keeps every cPanel action', function () {
    $registry = app(ModuleRegistry::class);
    $module = $registry->getServerModule('cpanelpro');

    expect($module)->toBeInstanceOf(CPanelProModule::class)
        ->and($module)->toBeInstanceOf(\Modules\Servers\CPanel\CPanelModule::class)
        ->and($registry->serverModuleNames())->toHaveKey('cpanelpro')
        ->and(method_exists($module, 'ssoLogin') && method_exists($module, 'liveUsage'))->toBeTrue()
        ->and($registry->getServerModule('cpanel'))->not->toBeInstanceOf(CPanelProModule::class); // built-in untouched
});

test('sizes from WHM become megabytes', function () {
    expect(CPanelProModule::megabytes('1536M'))->toBe(1536)
        ->and(CPanelProModule::megabytes('1.5G'))->toBe(1536)
        ->and(CPanelProModule::megabytes('2048K'))->toBe(2)
        ->and(CPanelProModule::megabytes('unlimited'))->toBe(0)
        ->and(CPanelProModule::megabytes(500))->toBe(500);
});

test('client page shows the login button and the usage strip', function () {
    cpFakeWhm();
    [$user, $service] = cpService();

    $this->actingAs($user)->get(route('client.services.show', $service))->assertOk()
        ->assertSee(route('client.services.login', $service), false)
        ->assertSee(route('client.services.usage', $service), false);
});

test('client "Log in to control panel" opens their own cPanel via a one-time WHM link', function () {
    cpFakeWhm();
    [$user, $service] = cpService();

    $this->actingAs($user)->get(route('client.services.login', $service))
        ->assertRedirect('https://whm.example.test:2083/cpsess123/login/?session=asha123:abc');

    Http::assertSent(fn ($r) => $r->url() === WHM.'create_user_session'
        && $r['user'] === 'asha123' && $r['service'] === 'cpaneld'
        && $r->hasHeader('Authorization', 'whm root:TOKEN123'));
});

test('another client cannot use the login link; a suspended service is refused', function () {
    cpFakeWhm();
    [, $service] = cpService();
    $stranger = User::factory()->create();
    $this->actingAs($stranger)->get(route('client.services.login', $service))->assertForbidden();

    [$owner, $suspended] = cpService(null, ['status' => 'Suspended', 'username' => 'susp1']);
    $this->actingAs($owner)->get(route('client.services.login', $suspended))->assertRedirect();
    Http::assertNotSent(fn ($r) => str_ends_with($r->url(), 'create_user_session') && $r['user'] === 'susp1');
});

test('live usage: disk, bandwidth, counts and domains for the client\'s own account', function () {
    cpFakeWhm();
    [$user, $service] = cpService();

    $u = $this->actingAs($user)->getJson(route('client.services.usage', $service))->assertOk()->json();

    expect($u['available'])->toBeTrue()
        ->and($u['disk'])->toBe(['used_mb' => 1536, 'quota_mb' => 10240])
        ->and($u['bandwidth'])->toBe(['used_mb' => 500, 'limit_mb' => 100000])
        ->and($u['counts'])->toBe(['emails' => 4, 'databases' => 2, 'ftp' => 1, 'domains' => 2, 'subdomains' => 3])
        ->and(array_column($u['domains'], 'name'))->toBe(['asha.example.com', 'shop.example.net']);

    Http::assertSent(fn ($r) => str_ends_with($r->url(), 'uapi_cpanel') && $r['cpanel.user'] === 'asha123');
    Http::assertNotSent(fn ($r) => isset($r['user']) && $r['user'] !== 'asha123' && ! str_ends_with($r->url(), 'create_user_session'));
});

test('usage degrades quietly when WHM is down', function () {
    Http::fake(['*' => Http::response('down', 503)]);
    [$user, $service] = cpService();

    expect($this->actingAs($user)->getJson(route('client.services.usage', $service))->assertOk()->json('available'))->toBeFalse();
    $this->actingAs($user)->get(route('client.services.login', $service))->assertRedirect()->assertSessionHas('error');
});

test('staff: panel on the service page, cPanel as the client, WHM login, activity log', function () {
    cpFakeWhm();
    [, $service] = cpService();
    $admin = cpAdmin();

    $this->actingAs($admin, 'admin')->get(route('admin.services.show', $service))->assertOk()
        ->assertSee('Log in to cPanel as asha123')->assertSee('Log in to WHM (WHM 1)');

    $this->actingAs($admin, 'admin')->post(route('cpanelpro.admin.cpanel', $service))
        ->assertRedirect('https://whm.example.test:2083/cpsess123/login/?session=asha123:abc');
    $this->actingAs($admin, 'admin')->post(route('cpanelpro.admin.whm', $service->server))
        ->assertRedirect('https://whm.example.test:2087/cpsess123/login/?session=root:abc');

    Http::assertSent(fn ($r) => str_ends_with($r->url(), 'create_user_session') && $r['user'] === 'root' && $r['service'] === 'whostmgrd');
    expect(\App\Models\ActivityLog::where('description', 'like', 'cPanel Pro: signed in to%')->count())->toBe(2);
});

test('staff links refuse non-Pro servers and staff without permission', function () {
    cpFakeWhm();
    $plain = cpServer(['type' => 'cpanel', 'hostname' => 'old.example.test']);
    $this->actingAs(cpAdmin(), 'admin')->post(route('cpanelpro.admin.whm', $plain))->assertNotFound();

    [, $service] = cpService();
    $this->actingAs(cpAdmin(full: false), 'admin')->post(route('cpanelpro.admin.whm', $service->server))->assertForbidden();
    $this->actingAs(cpAdmin(full: false), 'admin')->post(route('cpanelpro.admin.cpanel', $service))->assertForbidden();
    Http::assertNothingSent();
});

test('Live Servers lists cPanel Pro servers; the server form knows the type', function () {
    cpServer();
    $admin = cpAdmin();

    $this->actingAs($admin, 'admin')->get(route('admin.live-servers.index'))->assertOk()
        ->assertSee('cPanel/WHM Pro servers')->assertSee('whm.example.test')->assertSee('Log in to WHM');

    $this->actingAs($admin, 'admin')->get(route('admin.config.servers'))->assertOk()
        ->assertSee('value="cpanelpro"', false)->assertSee('cPanel/WHM Pro')
        ->assertSee('SERVER_TYPE_TUNING.cpanelpro = Object.assign({}, SERVER_TYPE_TUNING.cpanel', false);
});

test('provisioning still works: Create goes through WHM createacct', function () {
    Http::fake([WHM.'createacct' => Http::response(['metadata' => ['result' => 1, 'reason' => 'Account Creation Ok'], 'data' => []]), '*' => Http::response(['metadata' => ['result' => 1], 'data' => []])]);
    [, $service] = cpService(null, ['username' => '', 'status' => 'Pending']);

    $result = app(ModuleRegistry::class)->getServerModule('cpanelpro')->create($service->fresh());

    expect($result['success'])->toBeTrue();
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/json-api/createacct'));
});

test('admin pages relabel the type as cPanel/WHM Pro', function () {
    $this->actingAs(cpAdmin(), 'admin')->get(route('admin.dashboard'))->assertOk()->assertSee("o.textContent = 'cPanel/WHM Pro'", false);
});

// ======================================================================
// Step 2: the client hosting tools
// ======================================================================

/** A WHM that answers UAPI (uapi_cpanel), API2 (cpanel) and a cPanel session, like the real one. */
function cpFakeAll(): void
{
    $uapi = function (string $mod, string $fn, array $p) {
        $data = match ("{$mod}::{$fn}") {
            'DomainInfo::list_domains' => ['main_domain' => 'asha.example.com', 'addon_domains' => ['shop.example.net'], 'parked_domains' => [], 'sub_domains' => ['blog.asha.example.com']],
            'DomainInfo::domains_data' => ['sub_domains' => [['domain' => 'blog.asha.example.com', 'subdomain' => 'blog', 'rootdomain' => 'asha.example.com', 'documentroot' => '/home/asha123/public_html/blog']]],
            'Email::list_pops_with_disk' => [['email' => 'info@asha.example.com', 'domain' => 'asha.example.com', 'diskused' => '12.4', 'diskquota' => '1024'], ['email' => 'asha123', 'domain' => '', 'diskused' => 0]],
            'Mysql::list_databases' => [['database' => 'asha123_shop', 'users' => ['asha123_shopu']], ['database' => 'asha123_empty', 'users' => []]],
            'Mysql::list_users' => [['user' => 'asha123_shopu', 'databases' => ['asha123_shop']]],
            'Mysql::get_restrictions' => ['prefix' => 'asha123_', 'max_database_name_length' => 64],
            'Ftp::list_ftp_with_disk' => [['login' => 'asha123', 'type' => 'main'], ['login' => 'dev@asha.example.com', 'type' => 'sub', 'dir' => '/home/asha123/public_html/dev', 'diskused' => '3', 'diskquota' => 'unlimited']],
            'StatsBar::get_stats' => [['name' => 'ftpaccounts', '_count' => 1, '_max' => 10], ['name' => 'subdomains', '_count' => 1, '_max' => 'unlimited'], ['name' => 'emailaccounts', '_count' => 1, '_max' => 'unlimited']],
            'Fileman::list_files' => [
                ['file' => 'public_html', 'type' => 'dir', 'mtime' => 1763100000, 'nicemode' => '0750'],
                ['file' => 'notes.txt', 'type' => 'file', 'size' => 12, 'humansize' => '12 bytes', 'mtime' => 1763100000, 'nicemode' => '0644'],
                ['file' => 'backup-11.14.2026_10-00-00_asha123.tar.gz', 'type' => 'file', 'size' => 52428800, 'mtime' => 1763114400, 'nicemode' => '0600'],
            ],
            'Fileman::get_file_content' => ['content' => 'hello world', 'dir' => $p['dir'] ?? '', 'filename' => $p['file'] ?? ''],
            default => null,
        };

        return ['metadata' => ['result' => 1], 'data' => ['uapi' => ['status' => 1, 'errors' => null, 'data' => $data]]];
    };
    $api2 = function (string $mod, string $fn) {
        $data = match ("{$mod}::{$fn}") {
            'ZoneEdit::fetchzone_records' => [
                ['line' => 1, 'type' => 'SOA', 'name' => 'asha.example.com.', 'mname' => 'ns1.host.com.', 'serial' => '2026111401'],
                ['line' => 5, 'type' => 'NS', 'name' => 'asha.example.com.', 'nsdname' => 'ns1.host.com.', 'ttl' => 86400],
                ['line' => 8, 'type' => 'A', 'name' => 'asha.example.com.', 'address' => '203.0.113.10', 'ttl' => 14400],
                ['line' => 9, 'type' => 'A', 'name' => 'www.asha.example.com.', 'address' => '203.0.113.10', 'ttl' => 14400],
                ['line' => 12, 'type' => 'TXT', 'name' => 'asha.example.com.', 'txtdata' => 'v=spf1 +a -all', 'ttl' => 14400],
                ['line' => 13, 'type' => 'A', 'name' => 'app.asha.example.com.', 'address' => '198.51.100.7', 'ttl' => 300],
                ['line' => 14, 'type' => 'MX', 'name' => 'asha.example.com.', 'exchange' => 'mail.asha.example.com.', 'preference' => 0, 'ttl' => 14400],
                ['line' => 15, 'type' => ':RAW', 'raw' => '; comment'],
            ],
            'Cron::listcron' => [['line' => 1, 'minute' => '*/5', 'hour' => '*', 'day' => '*', 'month' => '*', 'weekday' => '*', 'command' => 'php /home/asha123/cron.php'], ['count' => 1]],
            default => [['result' => 1]],
        };

        return ['cpanelresult' => ['event' => ['result' => 1], 'data' => $data, 'error' => null]];
    };

    Http::fake(function ($r) use ($uapi, $api2) {
        $url = $r->url();
        if (str_ends_with($url, '/json-api/uapi_cpanel')) {
            return Http::response($uapi($r['cpanel.module'], $r['cpanel.function'], $r->data()));
        }
        if (str_ends_with($url, '/json-api/cpanel')) {
            return Http::response($api2($r['cpanel_jsonapi_module'], $r['cpanel_jsonapi_func']));
        }
        if (str_ends_with($url, '/json-api/create_user_session')) {
            return Http::response(['metadata' => ['result' => 1], 'data' => ['url' => 'https://whm.example.test:2083/cpsess4242/login/?session=x']]);
        }
        if (str_contains($url, ':2083/cpsess4242/login')) {
            return Http::response('ok', 200, ['Set-Cookie' => 'cpsession=abc; path=/']);
        }
        if (str_contains($url, ':2083/cpsess4242/download')) {
            return Http::response("BINARY\x00DATA", 200);
        }
        if (str_contains($url, ':2083/cpsess4242/execute/Fileman/upload_files')) {
            return Http::response(['status' => 1, 'data' => ['succeeded' => 1], 'errors' => null]);
        }

        return Http::response(['metadata' => ['result' => 1], 'data' => []]);
    });
}

function cpSent(string $module, string $fn): array
{
    return collect(Http::recorded())->map(fn ($p) => $p[0])
        ->filter(fn ($r) => ($r['cpanel.module'] ?? $r['cpanel_jsonapi_module'] ?? null) === $module && ($r['cpanel.function'] ?? $r['cpanel_jsonapi_func'] ?? null) === $fn)
        ->map(fn ($r) => $r->data())->values()->all();
}

test('the service page offers all 8 tools and every tool page shows the account\'s data', function () {
    cpFakeAll();
    [$user, $service] = cpService();
    $base = "/client/services/{$service->id}";

    $this->actingAs($user)->get(route('client.services.show', $service))->assertOk()
        ->assertSee("{$base}/emails", false)->assertSee("{$base}/files", false)->assertSee("{$base}/databases", false)->assertSee("{$base}/ftp", false)
        ->assertSee("{$base}/subdomains", false)->assertSee("{$base}/dns", false)->assertSee("{$base}/cron", false)->assertSee("{$base}/backups", false);

    $this->actingAs($user)->get("{$base}/emails")->assertOk()->assertSee('info@asha.example.com')->assertDontSee('>asha123<', false);
    $this->actingAs($user)->get("{$base}/databases")->assertOk()->assertSee('asha123_shop')->assertSee('asha123_shopu')->assertSee('asha123_empty');
    $this->actingAs($user)->get("{$base}/ftp")->assertOk()->assertSee('dev@asha.example.com')->assertSee('whm.example.test');
    $this->actingAs($user)->get("{$base}/subdomains")->assertOk()->assertSee('blog.asha.example.com');
    $this->actingAs($user)->get("{$base}/dns")->assertOk()->assertSee('198.51.100.7')->assertSee('v=spf1 +a -all');
    $this->actingAs($user)->get("{$base}/cron")->assertOk()->assertSee('php /home/asha123/cron.php');
    $this->actingAs($user)->get("{$base}/backups")->assertOk()->assertSee('backup-11.14.2026_10-00-00_asha123.tar.gz');
    $this->actingAs($user)->get("{$base}/files")->assertOk()->assertSee('notes.txt')->assertSee('public_html');
});

test('email: create on an own domain, refuse other domains and other mailboxes', function () {
    cpFakeAll();
    [$user, $service] = cpService();
    $base = "/client/services/{$service->id}";

    $this->actingAs($user)->post("{$base}/emails", ['domain_id' => 'shop.example.net', 'local_part' => 'sales', 'password' => 'Str0ng-pass!', 'quota_mb' => 500])->assertSessionHas('success');
    expect(cpSent('Email', 'add_pop'))->toHaveCount(1)->and(cpSent('Email', 'add_pop')[0])->toMatchArray(['email' => 'sales', 'domain' => 'shop.example.net', 'quota' => 500]);

    $this->actingAs($user)->post("{$base}/emails", ['domain_id' => 'victim.com', 'local_part' => 'x', 'password' => 'Str0ng-pass!'])->assertSessionHas('error');
    $this->actingAs($user)->post("{$base}/emails/delete", ['email_id' => 'boss@victim.com'])->assertSessionHas('error');
    $this->actingAs($user)->post("{$base}/emails/password", ['email_id' => 'info@asha.example.com', 'password' => 'An0ther-pass!'])->assertSessionHas('success');

    expect(cpSent('Email', 'add_pop'))->toHaveCount(1)->and(cpSent('Email', 'delete_pop'))->toBe([])
        ->and(cpSent('Email', 'passwd_pop')[0])->toMatchArray(['email' => 'info', 'domain' => 'asha.example.com']);
});

test('databases: prefixed names, users per database with roles, only own databases', function () {
    cpFakeAll();
    [$user, $service] = cpService();
    $base = "/client/services/{$service->id}";

    $this->actingAs($user)->post("{$base}/databases", ['domain_id' => 'account', 'database_name' => 'blog', 'database_user' => 'blogu', 'password' => 'Str0ng-pass!'])->assertSessionHas('success');
    expect(cpSent('Mysql', 'create_database')[0]['name'])->toBe('asha123_blog')
        ->and(cpSent('Mysql', 'create_user')[0]['name'])->toBe('asha123_blogu')
        ->and(cpSent('Mysql', 'set_privileges_on_database')[0])->toMatchArray(['user' => 'asha123_blogu', 'database' => 'asha123_blog', 'privileges' => 'ALL PRIVILEGES']);

    $this->actingAs($user)->post("{$base}/databases/users", ['domain_id' => 'db:asha123_empty', 'username' => 'reader', 'password' => 'Str0ng-pass!', 'role' => 'read'])->assertSessionHas('success');
    expect(cpSent('Mysql', 'set_privileges_on_database')[1])->toMatchArray(['user' => 'asha123_reader', 'database' => 'asha123_empty', 'privileges' => 'SELECT,SHOW VIEW']);

    $this->actingAs($user)->post("{$base}/databases/delete", ['domain_id' => 'db:other_db', 'database_name' => 'other_db'])->assertSessionHas('error');
    $this->actingAs($user)->post("{$base}/databases/users/delete", ['user_id' => 'root'])->assertSessionHas('error');
    $this->actingAs($user)->post("{$base}/databases/delete", ['domain_id' => 'db:asha123_shop', 'database_name' => 'asha123_shop'])->assertSessionHas('success');
    expect(cpSent('Mysql', 'delete_database'))->toHaveCount(1)->and(cpSent('Mysql', 'delete_database')[0])->toMatchArray(['cpanel.user' => 'asha123', 'name' => 'asha123_shop'])
        ->and(cpSent('Mysql', 'delete_user'))->toBe([]);
});

test('FTP: create with a home folder, never touch the main account', function () {
    cpFakeAll();
    [$user, $service] = cpService();
    $base = "/client/services/{$service->id}";

    $this->actingAs($user)->post("{$base}/ftp", ['username' => 'designer', 'password' => 'Str0ng-pass!', 'domain_id' => 'asha.example.com'])->assertSessionHas('success');
    expect(cpSent('Ftp', 'add_ftp')[0])->toMatchArray(['user' => 'designer', 'domain' => 'asha.example.com', 'homedir' => 'public_html/designer']);

    $this->actingAs($user)->post("{$base}/ftp/delete", ['ftp_id' => 'asha123'])->assertSessionHas('error');
    $this->actingAs($user)->post("{$base}/ftp/delete", ['ftp_id' => 'dev@asha.example.com'])->assertSessionHas('success');
    expect(cpSent('Ftp', 'delete_ftp'))->toHaveCount(1)->and(cpSent('Ftp', 'delete_ftp')[0])->toMatchArray(['user' => 'dev', 'domain' => 'asha.example.com', 'destroy' => 0]);
});

test('subdomains: create under an own domain (document root fenced to home), delete own', function () {
    cpFakeAll();
    [$user, $service] = cpService();
    $base = "/client/services/{$service->id}";

    $this->actingAs($user)->post("{$base}/subdomains", ['domain_id' => 'asha.example.com', 'name' => 'shop', 'ssl' => 1])->assertSessionHas('success');
    expect(cpSent('SubDomain', 'addsubdomain')[0])->toMatchArray(['domain' => 'shop', 'rootdomain' => 'asha.example.com', 'dir' => 'public_html/shop']);

    $this->actingAs($user)->post("{$base}/subdomains", ['domain_id' => 'asha.example.com', 'name' => 'evil', 'document_root' => '../../etc'])->assertSessionHas('error');
    $this->actingAs($user)->post("{$base}/subdomains", ['domain_id' => 'victim.com', 'name' => 'x'])->assertSessionHas('error');
    $this->actingAs($user)->post("{$base}/subdomains/delete", ['subdomain_id' => 'blog.asha.example.com'])->assertSessionHas('success');
    expect(cpSent('SubDomain', 'addsubdomain'))->toHaveCount(1)->and(cpSent('SubDomain', 'delsubdomain')[0]['domain'])->toBe('blog.asha.example.com');
});

test('DNS: one zone, relative names, site records locked, foreign records refused', function () {
    cpFakeAll();
    [$user, $service] = cpService();
    $base = "/client/services/{$service->id}";
    $records = collect(app(ModuleRegistry::class)->getServerModule('cpanelpro')->dnsRecords($service->fresh(), 'asha.example.com'))->keyBy('id');

    expect($records['asha.example.com|13'])->toMatchArray(['type' => 'A', 'name' => 'app', 'content' => '198.51.100.7', 'protected' => false])
        ->and($records['asha.example.com|8'])->toMatchArray(['name' => '@', 'protected' => true])
        ->and($records['asha.example.com|14'])->toMatchArray(['type' => 'MX', 'content' => 'mail.asha.example.com', 'priority' => 0])
        ->and($records->has('asha.example.com|15'))->toBeFalse();

    $this->actingAs($user)->post("{$base}/dns", ['domain_id' => 'asha.example.com', 'type' => 'A', 'name' => 'api', 'content' => '198.51.100.8', 'ttl' => 300])->assertSessionHas('success');
    expect(cpSent('ZoneEdit', 'add_zone_record')[0])->toMatchArray(['domain' => 'asha.example.com', 'name' => 'api.asha.example.com.', 'type' => 'A', 'address' => '198.51.100.8', 'ttl' => 300]);

    $this->actingAs($user)->post("{$base}/dns", ['domain_id' => 'asha.example.com', 'type' => 'A', 'name' => 'www', 'content' => '1.2.3.4'])->assertSessionHas('error');
    $this->actingAs($user)->post("{$base}/dns", ['domain_id' => 'asha.example.com', 'type' => 'A', 'name' => 'bad', 'content' => 'not-an-ip'])->assertSessionHas('error');
    $this->actingAs($user)->post("{$base}/dns/update", ['record_id' => 'asha.example.com|8', 'name' => '@', 'content' => '1.2.3.4'])->assertSessionHas('error');
    $this->actingAs($user)->post("{$base}/dns/delete", ['record_id' => 'victim.com|3'])->assertSessionHas('error');
    $this->actingAs($user)->post("{$base}/dns/update", ['record_id' => 'asha.example.com|13', 'name' => 'app', 'content' => '198.51.100.9', 'ttl' => 600])->assertSessionHas('success');
    $this->actingAs($user)->post("{$base}/dns/delete", ['record_id' => 'asha.example.com|12'])->assertSessionHas('success');

    expect(cpSent('ZoneEdit', 'add_zone_record'))->toHaveCount(1)
        ->and(cpSent('ZoneEdit', 'edit_zone_record')[0])->toMatchArray(['line' => 13, 'address' => '198.51.100.9', 'ttl' => 600])
        ->and(cpSent('ZoneEdit', 'remove_zone_record'))->toHaveCount(1)->and((string) cpSent('ZoneEdit', 'remove_zone_record')[0]['line'])->toBe('12');
});

test('cron: add from a preset, delete own; pause and run-now explain they are not available', function () {
    cpFakeAll();
    [$user, $service] = cpService();
    $base = "/client/services/{$service->id}";

    $this->actingAs($user)->post("{$base}/cron", ['domain_id' => 'asha.example.com', 'task_name' => 'Nightly', 'command' => 'php artisan schedule:run', 'schedule_type' => 'advanced', 'minute' => '0', 'hour' => '2', 'day_of_month' => '*', 'month' => '*', 'day_of_week' => '*'])->assertSessionHas('success');
    expect(cpSent('Cron', 'add_line')[0])->toMatchArray(['command' => 'php artisan schedule:run', 'minute' => '0', 'hour' => '2', 'day' => '*', 'weekday' => '*']);

    $this->actingAs($user)->post("{$base}/cron", ['domain_id' => 'asha.example.com', 'task_name' => 'x', 'command' => 'ls', 'schedule_type' => 'advanced', 'minute' => '0; rm -rf /'])->assertSessionHas('error');
    $this->actingAs($user)->post("{$base}/cron/delete", ['cron_id' => '99'])->assertSessionHas('error');
    $this->actingAs($user)->post("{$base}/cron/delete", ['cron_id' => '1'])->assertSessionHas('success');
    $this->actingAs($user)->post("{$base}/cron/toggle", ['cron_id' => '1'])->assertSessionHas('error');
    expect(cpSent('Cron', 'add_line'))->toHaveCount(1)->and((string) cpSent('Cron', 'remove_line')[0]['line'])->toBe('1');
});

test('backups: start a full backup, delete own backup files only', function () {
    cpFakeAll();
    [$user, $service] = cpService();
    $base = "/client/services/{$service->id}";

    $this->actingAs($user)->post("{$base}/backups", [])->assertSessionHas('success');
    expect(cpSent('Backup', 'fullbackup_to_homedir'))->toHaveCount(1);

    $this->actingAs($user)->post("{$base}/backups/delete", ['filename' => 'notes.txt'])->assertSessionHas('error');
    $this->actingAs($user)->post("{$base}/backups/delete", ['filename' => 'backup-11.14.2026_10-00-00_asha123.tar.gz'])->assertSessionHas('success');
    expect(cpSent('Fileman', 'fileop')[0])->toMatchArray(['op' => 'unlink', 'sourcefiles' => '/home/asha123/backup-11.14.2026_10-00-00_asha123.tar.gz']);
});

test('files: browse, edit, create, rename, delete (to trash), upload and download, all inside home', function () {
    cpFakeAll();
    [$user, $service] = cpService();
    $base = "/client/services/{$service->id}";

    $this->actingAs($user)->get("{$base}/files?path=/etc")->assertOk()->assertSee('outside your home folder');
    $this->actingAs($user)->get("{$base}/files?path=/home/asha123/../otheruser")->assertOk()->assertSee('outside your home folder');
    expect(collect(cpSent('Fileman', 'list_files'))->pluck('dir')->all())->toBe([]);

    $this->actingAs($user)->get("{$base}/files/edit?path=/home/asha123/notes.txt")->assertOk()->assertSee('hello world');
    $this->actingAs($user)->post("{$base}/files/save", ['path' => '/home/asha123/notes.txt', 'content' => 'new'])->assertRedirect();
    expect(cpSent('Fileman', 'save_file_content')[0])->toMatchArray(['dir' => '/home/asha123', 'file' => 'notes.txt', 'content' => 'new']);

    $this->actingAs($user)->post("{$base}/files/create", ['path' => '/home/asha123/public_html', 'name' => 'img', 'type' => 'folder'])->assertSessionHas('success');
    $this->actingAs($user)->post("{$base}/files/create", ['path' => '/home/asha123', 'name' => '../../x', 'type' => 'file'])->assertSessionHas('error');
    $this->actingAs($user)->post("{$base}/files/rename", ['path' => '/home/asha123/notes.txt', 'new_name' => 'readme.txt'])->assertSessionHas('success');
    $this->actingAs($user)->post("{$base}/files/delete", ['paths' => ['/home/asha123/readme.txt']])->assertSessionHas('success');
    $this->actingAs($user)->post("{$base}/files/delete", ['paths' => ['/home/asha123']])->assertSessionHas('error');

    expect(cpSent('Fileman', 'mkdir')[0])->toMatchArray(['path' => '/home/asha123/public_html', 'name' => 'img'])
        ->and(collect(cpSent('Fileman', 'fileop'))->pluck('op')->all())->toBe(['rename', 'trash']);

    $up = \Illuminate\Http\UploadedFile::fake()->createWithContent('logo.png', "PNG\x00\x01");
    $this->actingAs($user)->post("{$base}/files/upload", ['path' => '/home/asha123/public_html', 'file' => $up])->assertSessionHas('success');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/cpsess4242/execute/Fileman/upload_files'));

    $dl = $this->actingAs($user)->get("{$base}/files/download?path=/home/asha123/public_html/logo.png")->assertOk();
    expect($dl->getContent())->toBe("BINARY\x00DATA");
    Http::assertSent(fn ($r) => str_contains($r->url(), '/cpsess4242/download') && str_contains($r->url(), 'file=%2Fhome%2Fasha123%2Fpublic_html%2Flogo.png'));
    $this->actingAs($user)->get("{$base}/files/download?path=/etc/passwd")->assertRedirect();
});

test('another client cannot open or change this account\'s tools', function () {
    cpFakeAll();
    [, $service] = cpService();
    $stranger = User::factory()->create();
    $base = "/client/services/{$service->id}";

    $this->actingAs($stranger)->get("{$base}/emails")->assertForbidden();
    $this->actingAs($stranger)->post("{$base}/databases", ['domain_id' => 'account', 'database_name' => 'x', 'database_user' => 'y', 'password' => 'Str0ng-pass!'])->assertForbidden();
    expect(cpSent('Mysql', 'create_database'))->toBe([]);
});
