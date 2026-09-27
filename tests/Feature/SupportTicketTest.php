<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Reseller;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// Tickets between customers, resellers and the company: who sees what, and who may do what.
class SupportTicketTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->branch = Branch::firstOrFail();
    }

    private function api(string $uri, array $data = [])
    {
        return $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data);
    }

    private function reseller(string $username): Reseller
    {
        return Reseller::create(['code' => 'RS-' . $username, 'name' => "R {$username}", 'phone' => '01711' . rand(100000, 999999),
            'username' => $username, 'password' => Hash::make('x'), 'branch_id' => $this->branch->id]);
    }

    private function customer(string $phone, ?int $resellerId): Customer
    {
        $this->api('/customer', ['name' => "C {$phone}", 'phone' => $phone])->assertOk();
        $customer = Customer::where('phone', $phone)->firstOrFail();
        $customer->forceFill(['reseller_id' => $resellerId, 'username' => 'u' . $phone])->save();
        return $customer->fresh();
    }

    public function test_ticket_visibility_and_flow(): void
    {
        $reseller = $this->reseller('tk_res_1');
        $otherReseller = $this->reseller('tk_res_2');
        $resCustomer = $this->customer('01788800001', $reseller->id);
        $directCustomer = $this->customer('01788800002', null);

        // customer of a reseller opens a ticket: company and that reseller see it
        $id = $this->actingAs($resCustomer, 'customer')->postJson('/customer-portal/ticket', [
            'subject' => 'No internet', 'category' => 'connection', 'message' => 'Red light on ONU',
        ])->assertOk()->json('id');
        $ticket = Ticket::findOrFail($id);
        $this->assertEquals($reseller->id, $ticket->reseller_id);
        $this->assertEquals('open', $ticket->status);

        $this->actingAs($reseller, 'reseller')->postJson('/reseller/get-ticket', ['id' => $id])->assertOk()->assertJsonPath('isRequester', false);
        $this->actingAs($otherReseller, 'reseller')->postJson('/reseller/get-ticket', ['id' => $id])->assertNotFound();
        $this->actingAs($directCustomer, 'customer')->postJson('/customer-portal/get-ticket', ['id' => $id])->assertNotFound();

        // reseller answers as support -> in progress; internal notes are company-only
        $this->actingAs($reseller, 'reseller')->postJson('/reseller/ticket-reply', ['id' => $id, 'message' => 'Technician coming'])->assertOk();
        $this->assertEquals('in_progress', $ticket->fresh()->status);
        $this->api('/isp/ticket-reply', ['id' => $id, 'message' => 'Fiber cut in area', 'internal' => true])->assertOk();
        $customerView = $this->actingAs($resCustomer, 'customer')->postJson('/customer-portal/get-ticket', ['id' => $id])->assertOk()->json('replies');
        $this->assertNotContains('Fiber cut in area', array_column($customerView, 'message'));
        $resellerView = $this->actingAs($reseller, 'reseller')->postJson('/reseller/get-ticket', ['id' => $id])->json('replies');
        $this->assertNotContains('Fiber cut in area', array_column($resellerView, 'message'));
        $adminView = $this->api('/isp/get-ticket', ['id' => $id])->json('replies');
        $this->assertContains('Fiber cut in area', array_column($adminView, 'message'));
        $this->actingAs($reseller, 'reseller')->postJson('/reseller/ticket-reply', ['id' => $id, 'message' => 'x', 'internal' => true])->assertOk();
        $this->assertFalse((bool) \App\Models\TicketReply::where('ticket_id', $id)->latest('id')->value('is_internal'));

        // admin resolves, customer replies -> reopened; customer can't set in_progress, can close
        $this->api('/isp/ticket-status', ['id' => $id, 'status' => 'resolved'])->assertOk();
        $this->actingAs($resCustomer, 'customer')->postJson('/customer-portal/ticket-reply', ['id' => $id, 'message' => 'Still down'])->assertOk();
        $this->assertEquals('open', $ticket->fresh()->status);
        $this->actingAs($resCustomer, 'customer')->postJson('/customer-portal/ticket-status', ['id' => $id, 'status' => 'in_progress'])->assertStatus(422);
        $this->actingAs($resCustomer, 'customer')->postJson('/customer-portal/ticket-status', ['id' => $id, 'status' => 'closed'])->assertOk();
        $this->actingAs($resCustomer, 'customer')->postJson('/customer-portal/ticket-reply', ['id' => $id, 'message' => 'hello?'])->assertStatus(422);

        // reseller's own ticket to the company; a reseller can only open tickets for their own customers
        $this->actingAs($reseller, 'reseller')->postJson('/reseller/ticket', ['subject' => 'Withdrawal late', 'category' => 'reseller_account', 'message' => 'Please check'])->assertOk();
        $this->actingAs($reseller, 'reseller')->postJson('/reseller/ticket', ['subject' => 'x', 'message' => 'y', 'customer_id' => $directCustomer->id])->assertNotFound();
        $this->actingAs($reseller, 'reseller')->postJson('/reseller/get-tickets', ['status' => '', 'mine' => 'own'])->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($reseller, 'reseller')->postJson('/reseller/get-tickets', ['status' => ''])->assertOk()->assertJsonCount(2, 'data');

        // admin opens a ticket for a direct customer, assigns it; that customer sees it
        $adminTicket = $this->api('/isp/ticket', ['customer_id' => $directCustomer->id, 'subject' => 'Planned maintenance', 'message' => 'Tonight 2am'])->assertOk()->json('id');
        $this->api('/isp/ticket-update', ['id' => $adminTicket, 'assigned_to' => $this->admin->id, 'priority' => 'high'])->assertOk();
        $this->assertNull(Ticket::find($adminTicket)->reseller_id);
        $this->actingAs($directCustomer, 'customer')->postJson('/customer-portal/get-tickets', ['status' => ''])->assertOk()->assertJsonCount(1, 'data');
        $this->api('/isp/get-tickets', ['status' => '', 'needsReply' => true])->assertOk();

        foreach (['/customer-portal/tickets' => [$resCustomer, 'customer'], '/reseller/tickets' => [$reseller, 'reseller']] as $uri => [$user, $guard]) {
            $this->actingAs($user, $guard)->get($uri)->assertOk();
        }
        $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->get('/isp/tickets')->assertOk();
    }
}
