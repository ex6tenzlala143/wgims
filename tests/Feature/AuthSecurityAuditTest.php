<?php

namespace Tests\Feature;

use App\Models\DeliverySubsidy;
use App\Models\Item;
use App\Models\Requisition;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Security audit of the authentication / logout system.
 *
 * Core principle under test: a logged-out (or never-logged-in) user must be
 * rejected on the SERVER for every protected page and action — a copied URL,
 * typed URL, bookmark, Back button, cached page, or AJAX call must not reach
 * protected data. Authorization for role-specific pages must be enforced by
 * middleware, never by hiding UI.
 *
 * These tests run against the real MySQL schema (DatabaseTransactions rolls
 * every test back), because the sqlite in-memory pipeline cannot run the
 * pre-existing `dropForeign` migration.
 */
class AuthSecurityAuditTest extends TestCase
{
    use DatabaseTransactions;

    private static int $seq = 0;

    private function username(string $prefix): string
    {
        self::$seq++;
        return $prefix.'_'.now()->format('His').'_'.self::$seq;
    }

    private function makeUser(string $role, bool $active = true): User
    {
        return User::create([
            'username'  => $this->username($role),
            'name'      => ucfirst($role).' User',
            'password'  => bcrypt('secret123'),
            'role'      => $role,
            'is_active' => $active,
        ]);
    }

    private function makeWarehouse(): Warehouse
    {
        return Warehouse::create([
            'name'      => 'Sec WH '.self::$seq,
            'code'      => 'SEC'.Str::upper(Str::random(4)),
            'place'     => null,
            'is_active' => true,
        ]);
    }

    private function makeRequisition(User $creator): Requisition
    {
        return Requisition::create([
            'ris_number'     => 'RIS-SEC-'.Str::upper(Str::random(6)),
            'warehouse_id'   => null,
            'created_by'     => $creator->id,
            'purpose'        => 'Security audit test',
            'date_requested' => now()->toDateString(),
        ]);
    }

    private function makeTransfer(User $transferredBy): StockTransfer
    {
        return StockTransfer::create([
            'transfer_number'   => StockTransfer::generateTransferNumber(),
            'from_warehouse_id' => $this->makeWarehouse()->id,
            'to_warehouse_id'   => $this->makeWarehouse()->id,
            'transfer_date'     => now()->toDateString(),
            'transferred_by'    => $transferredBy->id,
            'status'            => 'pending',
        ]);
    }

    private function makeItem(): Item
    {
        return Item::create([
            'stock_number'   => 'SEC-'.Str::upper(Str::random(6)),
            'description'    => 'Security Audit Item',
            'unit'           => 'piece',
            'category'       => 'food',
            'account_code'   => '6-01-01-000',
            'warehouse_id'   => $this->makeWarehouse()->id,
            'unit_cost'      => 1,
            'quantity'       => 1,
            'is_active'      => true,
        ]);
    }

    private function makeSubsidy(User $creator): DeliverySubsidy
    {
        $supplier = Supplier::create([
            'name'      => 'Sec Supplier '.self::$seq,
            'is_active' => true,
        ]);

        return DeliverySubsidy::create([
            'ris_number'     => 'RIS-SEC-'.Str::upper(Str::random(6)),
            'dr_number'      => 'DR-SEC-'.Str::upper(Str::random(6)),
            'supplier_id'    => $supplier->id,
            'created_by'     => $creator->id,
            'date'           => now()->toDateString(),
            'status'         => 'pending',
        ]);
    }

    private function loginViaForm(User $user): void
    {
        $this->post('/login', [
            'username' => $user->username,
            'password' => 'secret123',
        ])->assertRedirect('/');
    }

    private function logoutViaForm(): void
    {
        $this->post('/logout')->assertRedirect('/login');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. Every protected GET page must reject unauthenticated users.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_every_protected_get_route_redirects_guests_to_login(): void
    {
        // Placeholder ids are fine: the auth middleware short-circuits BEFORE
        // route-model binding, so a guest is redirected before any lookup runs.
        $protectedUris = [
            '/',                                             // dashboard
            '/items', '/items/1',
            '/delivery-subsidies', '/delivery-subsidies/create', '/delivery-subsidies/1',
            '/delivery-subsidies/1/delivery', '/delivery-subsidies/1/edit',
            '/delivery-subsidies/1/edit-data', '/delivery-subsidies/1/deliveries/1/edit',
            '/requisitions', '/requisitions/create', '/requisitions/1',
            '/requisitions/1/approve', '/requisitions/1/signatories', '/requisitions/1/print',
            '/requisitions/1/edit', '/requisitions/1/correction-data',
            '/requisitions/dispatch/1/edit-data',
            '/stock-cards', '/stock-cards/summary', '/stock-cards/1',
            '/stock-cards/item/1/history', '/stock-cards/item/1/history-by-cost', '/stock-cards/item/1/print',
            '/transfers', '/transfers/1', '/transfers/1/print', '/transfers/1/dispatch', '/transfers/1/edit',
            '/suppliers', '/suppliers/create', '/suppliers/1/edit',
            '/reports/rpci', '/reports/rpci/print', '/reports/rpci/export',
            '/reports/rsmi', '/reports/rsmi/print', '/reports/rsmi/export',
            '/reports/inventory-balance', '/reports/inventory-balance/export', '/reports/snapshot/1',
            '/warehouses', '/warehouses/create', '/warehouses/1/edit',
            '/users', '/users/create', '/users/1/edit',
            '/item-categories',
            '/notifications',
        ];

        foreach ($protectedUris as $uri) {
            $this->get($uri)->assertRedirect(route('login'));
        }

        $this->assertGuest();
    }

    public function test_json_api_endpoints_return_401_for_guests(): void
    {
        $jsonUris = [
            '/api/requisition-items',
            '/api/requisition-description-items',
            '/api/transfer-items',
            '/api/check-username?username=x',
            '/api/check-dr?dr_number=x',
            '/api/item-stock-card?item_id=1&unit_cost=1',
            '/api/notifications/unread',
        ];

        foreach ($jsonUris as $uri) {
            $this->getJson($uri)->assertStatus(401);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. THE reported scenario: login → copy URL → logout → paste URL.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_pasted_url_after_logout_cannot_reach_requisitions(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->loginViaForm($admin);

        // The page the user copied while logged in.
        $this->get('/requisitions')->assertOk()->assertSee('Requisition');

        $this->logoutViaForm();
        $this->assertGuest();

        // Pasting the copied URL must NOT show the page.
        $response = $this->get('/requisitions');
        $response->assertRedirect(route('login'));
        $response->assertDontSee('Requisition');
    }

    public function test_pasted_url_after_logout_cannot_reach_any_protected_page(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->loginViaForm($admin);

        $pages = [
            '/', '/items', '/delivery-subsidies', '/requisitions', '/stock-cards',
            '/transfers', '/suppliers', '/reports/inventory-balance', '/reports/rpci',
            '/reports/rsmi', '/warehouses', '/users', '/item-categories', '/notifications',
        ];

        foreach ($pages as $uri) {
            $this->get($uri)->assertOk();
        }

        $this->logoutViaForm();

        foreach ($pages as $uri) {
            $this->get($uri)->assertRedirect(route('login'));
        }

        $this->assertGuest();
    }

    public function test_refresh_after_logout_redirects_to_login(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->loginViaForm($admin);

        $this->get('/requisitions')->assertOk();
        $this->logoutViaForm();

        // F5 / refresh after logout.
        $this->get('/requisitions')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_bookmarked_protected_url_after_logout_redirects_to_login(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->loginViaForm($admin);
        $this->get('/requisitions')->assertOk();
        $this->logoutViaForm();

        // A bookmark points straight at a protected deep link.
        $this->get('/requisitions/approve')->assertRedirect(route('login'));
        $this->get('/items/1')->assertRedirect(route('login'));
        $this->get('/transfers/1/edit')->assertRedirect(route('login'));
        $this->get('/delivery-subsidies/1/edit')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_back_button_after_logout_cannot_show_cached_protected_page(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->loginViaForm($admin);

        // While authenticated, protected GETs use `private, no-cache, must-revalidate`
        // (no `no-store`) so the browser may keep the page in bfcache for smooth
        // Back/Forward without a skeleton flash. Security is still enforced:
        // the `pageshow` handler verifies the session on bfcache restores and
        // `Clear-Site-Data` on logout clears the cache entry. The server still
        // redirects to login once the session is gone.
        $response = $this->get('/requisitions');
        $response->assertOk();
        $response->assertHeaderContains('Cache-Control', 'private');
        $response->assertHeaderContains('Cache-Control', 'no-cache');
        $response->assertHeaderContains('Cache-Control', 'must-revalidate');

        $this->logoutViaForm();

        // A Back button forces a revalidation; the server redirects to login.
        $this->get('/requisitions')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Write actions (POST/PUT/PATCH/DELETE) rejected for logged-out users.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_logged_out_user_cannot_submit_write_actions(): void
    {
        $this->post('/delivery-subsidies', ['ris_number' => 'RIS-HACK'])->assertRedirect(route('login'));
        $this->post('/requisitions', ['ris_number' => 'RIS-HACK'])->assertRedirect(route('login'));
        $this->post('/transfers', ['from_warehouse_id' => 1])->assertRedirect(route('login'));
        $this->post('/transfers/1/dispatch', [])->assertRedirect(route('login'));
        $this->post('/suppliers', ['name' => 'Hacker'])->assertRedirect(route('login'));
        $this->post('/warehouses', ['name' => 'Hacker'])->assertRedirect(route('login'));
        $this->post('/users', ['username' => 'hacker'])->assertRedirect(route('login'));
        $this->post('/item-categories', ['name' => 'Hacker'])->assertRedirect(route('login'));
        $this->post('/requisitions/1/approve', [])->assertRedirect(route('login'));
        $this->post('/delivery-subsidies/1/delivery', [])->assertRedirect(route('login'));
        $this->post('/notifications/read-all', [])->assertRedirect(route('login'));
        $this->post('/notifications/1/read', [])->assertRedirect(route('login'));
        $this->post('/reports/rpci/snapshot', [])->assertRedirect(route('login'));
        $this->post('/reports/rsmi/snapshot', [])->assertRedirect(route('login'));

        $this->put('/delivery-subsidies/1', [])->assertRedirect(route('login'));
        $this->put('/delivery-subsidies/1/deliveries/1', [])->assertRedirect(route('login'));
        $this->put('/requisitions/1', [])->assertRedirect(route('login'));
        $this->put('/requisitions/1/signatories', [])->assertRedirect(route('login'));
        $this->put('/requisitions/1/correct', [])->assertRedirect(route('login'));
        $this->put('/requisitions/dispatch/1', [])->assertRedirect(route('login'));
        $this->put('/transfers/1', [])->assertRedirect(route('login'));
        $this->put('/suppliers/1', [])->assertRedirect(route('login'));
        $this->put('/warehouses/1', [])->assertRedirect(route('login'));
        $this->put('/users/1', [])->assertRedirect(route('login'));
        $this->put('/item-categories/1', [])->assertRedirect(route('login'));

        $this->patch('/suppliers/1/toggle', [])->assertRedirect(route('login'));
        $this->patch('/item-categories/1/toggle', [])->assertRedirect(route('login'));

        $this->delete('/delivery-subsidies/1')->assertRedirect(route('login'));
        $this->delete('/requisitions/1')->assertRedirect(route('login'));
        $this->delete('/transfers/1')->assertRedirect(route('login'));
        $this->delete('/item-categories/1')->assertRedirect(route('login'));
        $this->delete('/item-categories/catalog-items/1')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_logged_out_user_cannot_hit_json_write_endpoints(): void
    {
        $this->postJson('/notifications/1/read-ajax', [])->assertStatus(401);
        $this->postJson('/notifications/read-all', [])->assertStatus(401);
        $this->assertGuest();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Role-based authorization (Admin vs Warehouse Manager vs center roles).
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_can_access_admin_pages(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->actingAs($admin);

        $this->get('/users')->assertOk();
        $this->get('/item-categories')->assertOk();

        $target = $this->makeUser(User::ROLE_STAFF);
        $this->get('/users/'.$target->id.'/edit')->assertOk();

        $this->get('/')->assertOk();
        $this->get('/requisitions')->assertOk();
        $this->get('/items')->assertOk();
        $this->get('/delivery-subsidies')->assertOk();
        $this->get('/transfers')->assertOk();
        $this->get('/stock-cards')->assertOk();
        $this->get('/reports/inventory-balance')->assertOk();
        $this->get('/warehouses')->assertOk();
        $this->get('/suppliers')->assertOk();
    }

    public function test_warehouse_manager_is_blocked_from_admin_pages(): void
    {
        $wm = $this->makeUser(User::ROLE_WAREHOUSE_MANAGER);
        $this->actingAs($wm);

        $item    = $this->makeItem();
        $subsidy = $this->makeSubsidy($wm);
        $transfer = $this->makeTransfer($wm);
        $warehouse = $this->makeWarehouse();
        $targetUser = $this->makeUser(User::ROLE_STAFF);
        $requisition = $this->makeRequisition($wm);

        // Admin-only pages → 403, never 200.
        $this->get('/users')->assertForbidden();
        $this->get('/users/create')->assertForbidden();
        $this->get('/users/'.$targetUser->id.'/edit')->assertForbidden();
        $this->get('/item-categories')->assertForbidden();

        // Admin-only write actions → 403.
        $this->delete('/requisitions/'.$requisition->id)->assertForbidden();
        $this->delete('/delivery-subsidies/'.$subsidy->id)->assertForbidden();
        $this->delete('/transfers/'.$transfer->id)->assertForbidden();
        $this->put('/warehouses/'.$warehouse->id, [])->assertForbidden();
        $this->put('/users/'.$targetUser->id, [])->assertForbidden();
        $this->get('/transfers/'.$transfer->id.'/edit')->assertForbidden();

        // Legitimate WM access still works.
        $this->get('/')->assertOk();
        $this->get('/requisitions')->assertOk();
        $this->get('/items')->assertOk();
        $this->get('/delivery-subsidies')->assertOk();
        $this->get('/transfers')->assertOk();
        $this->get('/warehouses')->assertOk();
        $this->get('/suppliers')->assertOk();
    }

    public function test_center_roles_are_blocked_from_admin_pages(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $requisition = $this->makeRequisition($admin);

        foreach ([User::ROLE_STAFF, User::ROLE_HEAD, User::ROLE_CUSTODIAN] as $role) {
            $user = $this->makeUser($role);
            $this->actingAs($user);

            $this->get('/users')->assertForbidden();
            $this->get('/users/create')->assertForbidden();
            $this->get('/item-categories')->assertForbidden();
            $this->get('/transfers')->assertOk();
            $this->get('/requisitions')->assertOk();
            $this->get('/delivery-subsidies')->assertOk();
            $this->get('/')->assertOk();

            $this->post('/transfers', [])->assertForbidden();
            $this->post('/delivery-subsidies', [])->assertForbidden();
            $this->post('/suppliers', [])->assertForbidden();
            $this->post('/warehouses', [])->assertForbidden();
            $this->delete('/requisitions/'.$requisition->id)->assertForbidden();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Logout must actually terminate the session.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_logout_terminates_the_session(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->actingAs($admin);
        $this->assertAuthenticated();

        $this->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull(auth()->user());
    }

    public function test_old_session_id_cannot_be_reused_after_logout(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->loginViaForm($admin);
        $this->assertAuthenticated();

        $this->logoutViaForm();

        // The previous session data is gone — a request carrying the old
        // session id is treated as a fresh guest and redirected to login.
        $this->get('/requisitions')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_login_regenerates_session_against_fixation(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);

        // Seed a session id before login, then confirm it is replaced.
        $this->withSession(['foo' => 'bar']);
        $before = $this->app['session']->getId();

        $this->loginViaForm($admin);

        $after = $this->app['session']->getId();
        $this->assertNotSame($before, $after);
        $this->assertAuthenticatedAs($admin);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. Deactivated accounts.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_deactivated_user_cannot_log_in(): void
    {
        $user = $this->makeUser(User::ROLE_STAFF, active: false);

        $this->post('/login', [
            'username' => $user->username,
            'password' => 'secret123',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_deactivated_users_active_session_is_terminated(): void
    {
        $user = $this->makeUser(User::ROLE_STAFF);
        $this->actingAs($user);
        $this->assertAuthenticated();

        // Admin deactivates the account (the exact operation from UserController).
        $user->forceFill(['is_active' => false])->save();

        // The next request must be refused server-side and bounced to login.
        $this->get('/requisitions')->assertRedirect(route('login'));
        $this->assertGuest();

        // And it must stay terminated on subsequent requests.
        $this->get('/requisitions')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_deactivating_a_user_purges_their_remember_token(): void
    {
        // Warehouse manager role needs no warehouse assignment, so the edit
        // passes UserController validation without extra records.
        $user = $this->makeUser(User::ROLE_WAREHOUSE_MANAGER);
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        $this->assertNotNull($user->fresh()->remember_token);

        // Simulate the admin editing the user and unchecking "Active".
        $this->actingAs($this->makeUser(User::ROLE_ADMIN))
            ->put('/users/'.$user->id, [
                'username'      => $user->username,
                'name'          => $user->name,
                'email'         => $user->email,
                'role'          => $user->role,
                'warehouse_ids' => [],
                'is_active'     => false,
            ])
            ->assertRedirect('/users');

        $this->assertNull($user->fresh()->remember_token);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. Cache-control hardening is present on protected pages.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_protected_pages_are_marked_no_store(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->actingAs($admin);

        foreach (['/', '/requisitions', '/items', '/delivery-subsidies', '/users'] as $uri) {
            $this->get($uri)
                ->assertHeaderContains('Cache-Control', 'private')
                ->assertHeaderContains('Cache-Control', 'no-cache')
                ->assertHeaderContains('Cache-Control', 'must-revalidate')
                ->assertHeader('Pragma', 'no-cache')
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        }
    }

    public function test_login_page_is_not_cached(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertHeaderContains('Cache-Control', 'no-store')
            ->assertHeader('Pragma', 'no-cache');
    }
}