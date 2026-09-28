<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

// Audit C1: every older write endpoint names the permission it needs (`access:` route middleware),
// and user / role management can't be used to climb above one's own rank.
class LegacyAccessTest extends TestCase
{
    use DatabaseTransactions;

    private Branch $branch;
    private Branch $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::firstOrFail();
        $this->otherBranch = Branch::where('id', '!=', $this->branch->id)->first()
            ?? Branch::forceCreate(['code' => 'B-LAT', 'name' => 'Other branch', 'title' => 'Other', 'status' => 'a']);
    }

    private function role(string $name, array $access): Role
    {
        return Role::forceCreate(['name' => $name, 'access' => json_encode($access), 'status' => 'a', 'branch_id' => $this->branch->id]);
    }

    private function user(string $role, string $username, ?Branch $branch = null): User
    {
        return User::forceCreate([
            'code' => 'U-' . $username, 'name' => 'Access ' . $role, 'username' => $username, 'email' => "$username@example.test",
            'phone' => '01700000000', 'role' => $role, 'password' => Hash::make('secret-pass'), 'status' => 'a',
            'ipAddress' => '127.0.0.1', 'branch_id' => ($branch ?? $this->branch)->id,
        ]);
    }

    private function as(User $user)
    {
        return $this->actingAs($user)->withSession(['branch' => Branch::find($user->branch_id)]);
    }

    public function test_every_protected_route_refuses_a_user_without_its_permission(): void
    {
        $this->role('LatConnectionOnly', ['connection']);
        $user = $this->user('LatConnectionOnly', 'lat_conn');

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => collect($r->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'access:')));
        $this->assertGreaterThan(80, $routes->count());

        foreach ($routes as $route) {
            $uri = '/' . ltrim(preg_replace('/\{[^}]+\}/', '1', $route->uri()), '/');
            $method = in_array('POST', $route->methods()) ? 'postJson' : 'getJson';
            $this->as($user)->{$method}($uri, [])->assertStatus(403, "{$method} {$uri}");
        }
    }

    public function test_a_guest_is_sent_to_login_not_given_a_403(): void
    {
        $this->post('/update-user', [])->assertRedirect();
    }

    public function test_a_low_permission_user_can_no_longer_make_themselves_superadmin(): void
    {
        // the audit's proof: role allows only `connection`, POST /update-user on self with role=Superadmin
        $this->role('LatConnectionOnly', ['connection']);
        $user = $this->user('LatConnectionOnly', 'lat_self');
        $this->as($user)->postJson('/update-user', ['id' => $user->id, 'name' => 'x', 'username' => 'lat_self', 'phone' => '1', 'email' => 'a@b.c', 'role' => 'Superadmin'])
            ->assertStatus(403);
        $this->as($user)->postJson('/branch', ['name' => 'Rogue branch'])->assertStatus(403);
        $this->assertSame('LatConnectionOnly', $user->fresh()->role);
    }

    public function test_user_managers_cannot_climb_above_their_rank_or_leave_their_branch(): void
    {
        $this->role('LatUserManager', ['user']);
        $manager = $this->user('LatUserManager', 'lat_mgr');
        $superadmin = User::where('role', 'Superadmin')->firstOrFail();
        $base = ['name' => 'New', 'phone' => '1', 'email' => 'n@x.test', 'password' => 'pw-123456'];

        // an ordinary user in their own branch: fine
        $this->as($manager)->postJson('/user', $base + ['username' => 'lat_new', 'role' => 'LatUserManager', 'branch_id' => $this->branch->id])->assertOk();
        $created = User::where('username', 'lat_new')->firstOrFail();
        $this->assertSame($this->branch->id, (int) $created->branch_id);

        // a role above their own, another branch, a switch list, a Superadmin, themselves
        $this->as($manager)->postJson('/user', $base + ['username' => 'lat_x1', 'role' => 'admin'])->assertStatus(403);
        $this->as($manager)->postJson('/user', $base + ['username' => 'lat_x2', 'role' => 'LatUserManager', 'branch_id' => $this->otherBranch->id])->assertStatus(403);
        $this->as($manager)->postJson('/user', $base + ['username' => 'lat_x3', 'role' => 'LatUserManager', 'switchable_branches' => (string) $this->otherBranch->id])->assertStatus(403);
        $this->as($manager)->postJson('/update-user', ['id' => $superadmin->id, 'name' => 'x', 'username' => $superadmin->username, 'phone' => '1', 'email' => 'a@b.c', 'role' => 'Superadmin'])->assertStatus(403);
        $this->as($manager)->postJson('/delete-user', ['id' => $superadmin->id])->assertStatus(403);
        $this->as($manager)->postJson('/delete-user', ['id' => $manager->id])->assertStatus(403);

        // a user of another branch is out of reach
        $foreign = $this->user('LatUserManager', 'lat_foreign', $this->otherBranch);
        $this->as($manager)->postJson('/update-user', ['id' => $foreign->id, 'name' => 'Moved', 'username' => 'lat_foreign', 'phone' => '1', 'email' => 'a@b.c', 'role' => 'LatUserManager'])->assertStatus(403);
        $this->assertSame('Access LatUserManager', $foreign->fresh()->name);

        // mass assignment: fields outside the list are ignored
        $this->as($manager)->postJson('/update-user', ['id' => $created->id, 'name' => 'Renamed', 'username' => 'lat_new', 'phone' => '1', 'email' => 'a@b.c', 'role' => 'LatUserManager', 'two_factor_secret' => 'x', 'code' => 'HACK'])->assertOk();
        $created->refresh();
        $this->assertSame(['Renamed', null], [$created->name, $created->two_factor_secret]);
        $this->assertNotSame('HACK', $created->code);
    }

    public function test_login_as_never_reaches_a_higher_rank(): void
    {
        $this->role('LatSwitcher', ['userSwitch']);
        $switcher = $this->user('LatSwitcher', 'lat_switch');
        $superadmin = User::where('role', 'Superadmin')->firstOrFail();
        $this->as($switcher)->get("/user/{$superadmin->id}/login-as");
        $this->assertAuthenticatedAs($switcher);
    }

    public function test_roles_cannot_be_named_admin_or_grant_more_than_the_granter_has(): void
    {
        $this->role('LatRoleManager', ['role', 'customer']);
        $manager = $this->user('LatRoleManager', 'lat_roles');
        $this->as($manager)->postJson('/role', ['name' => 'Admin'])->assertStatus(422);
        $this->as($manager)->postJson('/role', ['name' => 'Superadmin'])->assertStatus(422);
        // `access` can't ride along on a role create
        $this->as($manager)->postJson('/role', ['name' => 'LatClerk', 'access' => json_encode(['user'])])->assertOk();
        $clerk = Role::where('name', 'LatClerk')->firstOrFail();
        $this->assertEmpty(json_decode((string) $clerk->access, true));

        $this->as($manager)->postJson('/save-roleAccess', ['id' => $clerk->id, 'access' => ['customer']])->assertOk();
        $this->as($manager)->postJson('/save-roleAccess', ['id' => $clerk->id, 'access' => ['customer', 'user']])->assertStatus(403);
        $own = Role::where('name', 'LatRoleManager')->firstOrFail();
        $this->as($manager)->postJson('/save-roleAccess', ['id' => $own->id, 'access' => ['role', 'customer', 'branch']])->assertStatus(403);
        $this->assertSame(['customer'], json_decode(Role::find($clerk->id)->access, true));
    }

    public function test_anyone_can_edit_their_own_profile_but_not_their_role(): void
    {
        $this->role('LatConnectionOnly', ['connection']);
        $user = $this->user('LatConnectionOnly', 'lat_profile');
        $this->as($user)->postJson('/update-profile', ['name' => 'Me', 'username' => 'lat_profile', 'phone' => '2', 'email' => 'me@x.test', 'role' => 'Superadmin', 'password' => 'new-pass-1'])->assertOk();
        $user->refresh();
        $this->assertSame(['Me', 'LatConnectionOnly'], [$user->name, $user->role]);
        $this->assertTrue(Hash::check('new-pass-1', $user->password));
    }
}
