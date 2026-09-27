<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\NetworkBlock;
use App\Models\User;
use App\Services\Network\NetworkBlockService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NetworkBlockTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        config(['isp.network_driver' => \App\Services\Network\NullNetworkDriver::class]); // never touch real routers
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->branch = Branch::firstOrFail();
    }

    private function api(string $uri, array $data = [])
    {
        return $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data);
    }

    public function test_input_is_cleaned_to_a_domain_or_ip(): void
    {
        $this->assertSame(['domain', 'facebook.com'], NetworkBlockService::parse('https://www.Facebook.com/some/page?x=1'));
        $this->assertSame(['domain', 'm.example.co.uk'], NetworkBlockService::parse('*.m.example.co.uk:443'));
        $this->assertSame(['ip', '203.0.113.9'], NetworkBlockService::parse(' 203.0.113.9 '));
        $this->assertSame(['ip', '198.51.100.0/24'], NetworkBlockService::parse('198.51.100.0/24'));
        foreach (['300.1.1.1', '10.0.0.0/4', 'not a site', 'localhost'] as $bad) {
            try {
                NetworkBlockService::parse($bad);
                $this->fail("{$bad} should be rejected");
            } catch (\RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_block_list_add_toggle_remove(): void
    {
        $this->api('/isp/block', ['values' => "https://tbk-site.com/x\n203.0.113.77\ntbk-site.com", 'scope' => 'all', 'note' => 'test'])
            ->assertOk()->assertJsonPath('status', true);
        $rows = NetworkBlock::where('branch_id', $this->branch->id)->whereIn('value', ['tbk-site.com', '203.0.113.77'])->get();
        $this->assertCount(2, $rows); // the duplicate line is added once
        $this->assertEquals(['domain', 'ip'], $rows->sortBy('id')->pluck('type')->all());

        // again: already in the list, nothing new
        $this->api('/isp/block', ['values' => 'tbk-site.com', 'scope' => 'all'])->assertOk();
        $this->assertEquals(1, NetworkBlock::where('value', 'tbk-site.com')->count());
        $this->api('/isp/block', ['values' => 'not a site', 'scope' => 'all'])->assertStatus(422);
        $this->api('/isp/block', ['values' => 'tbk-site.com', 'scope' => 'package'])->assertStatus(422); // needs a package

        $block = $rows->firstWhere('value', 'tbk-site.com');
        $this->api('/isp/block-toggle', ['id' => $block->id])->assertOk();
        $this->assertFalse($block->fresh()->is_active);
        $this->api('/isp/delete-block', ['ids' => $rows->pluck('id')->all()])->assertOk();
        $this->assertEquals(0, NetworkBlock::whereIn('id', $rows->pluck('id'))->count());
        $this->assertTrue(DB::table('audit_logs')->where('action', 'network_block.deleted')->exists());

        // staff without the permission can't
        $role = \App\Models\Role::create(['name' => 'T No Block', 'access' => json_encode(['router'])]);
        $staff = User::create(['name' => 'T No Block', 'username' => 't_noblock_' . uniqid(), 'role' => $role->name, 'branch_id' => $this->branch->id, 'ipAddress' => '127.0.0.1']);
        $this->actingAs($staff)->withSession(['branch' => $this->branch])->postJson('/isp/block', ['values' => 'x-site.com', 'scope' => 'all'])->assertForbidden();
    }
}
