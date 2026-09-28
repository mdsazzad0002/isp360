<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Support\Upload;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

// Audit C3: an upload can never become an executable file under public/, and ticket attachments
// are private and only served to people who may read the ticket.
class UploadSafetyTest extends TestCase
{
    use DatabaseTransactions;

    private array $written = [];
    private array $fakes = []; // a fake's temp file lives as long as the object

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            deleteUploadedFile($path);
        }
        parent::tearDown();
    }

    // A real JPEG sent under another name.
    private function jpegNamed(string $name): UploadedFile
    {
        $jpeg = $this->fakes[] = UploadedFile::fake()->image('real.jpg', 10, 10);
        return new UploadedFile($jpeg->getPathname(), $name, null, null, true);
    }

    private function phpNamed(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($path, "<?php echo 'owned'; ?>\n");
        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_the_extension_comes_from_the_content_not_the_name(): void
    {
        $path = Upload::toPublic($this->jpegNamed('shell.php'), 'uploads/user', 'U1');
        $this->written[] = $path;
        $this->assertMatchesRegularExpression('#^uploads/user/U1_[A-Za-z0-9]{24}\.jpg$#', $path);
        $this->assertFileExists(public_path($path));

        $this->expectException(RuntimeException::class);
        Upload::toPublic($this->phpNamed('avatar.jpg'), 'uploads/user', 'U1');
    }

    public function test_forms_refuse_a_script_disguised_as_a_picture(): void
    {
        $admin = User::where('role', 'Superadmin')->firstOrFail();
        $branch = Branch::firstOrFail();
        $this->actingAs($admin)->withSession(['branch' => $branch])
            ->post('/update-profile', ['name' => $admin->name, 'username' => $admin->username, 'phone' => '1', 'image' => $this->phpNamed('me.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertNotSame('me.jpg', basename((string) $admin->fresh()->image));
    }

    public function test_delete_never_leaves_the_uploads_folder(): void
    {
        $outside = public_path('keep-me-' . uniqid() . '.txt');
        file_put_contents($outside, 'x');
        deleteUploadedFile('uploads/../' . basename($outside));
        $this->assertFileExists($outside);
        unlink($outside);
    }

    public function test_ticket_attachments_are_private_and_served_only_to_readers(): void
    {
        $branch = Branch::firstOrFail();
        $owner = Customer::forceCreate(['code' => 'CI-UP1', 'name' => 'Owner', 'phone' => '01711110001', 'branch_id' => $branch->id, 'status' => 'a']);
        $stranger = Customer::forceCreate(['code' => 'CI-UP2', 'name' => 'Stranger', 'phone' => '01711110002', 'branch_id' => $branch->id, 'status' => 'a']);

        // a picture named .php is refused outright
        $this->actingAs($owner, 'customer')->post('/customer-portal/ticket', [
            'subject' => 'Router photo', 'message' => 'See attached', 'attachment' => $this->jpegNamed('router.php'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
        $id = $this->actingAs($owner, 'customer')->post('/customer-portal/ticket', [
            'subject' => 'Router photo', 'message' => 'See attached', 'attachment' => $this->jpegNamed('router.jpg'),
        ], ['Accept' => 'application/json'])->assertOk()->json('id');

        $reply = TicketReply::where('ticket_id', $id)->firstOrFail();
        $this->assertStringStartsWith("tickets/{$branch->id}/", $reply->attachment);
        $this->assertStringEndsWith('.jpg', $reply->attachment);
        $this->assertTrue(Storage::disk(Upload::PRIVATE_DISK)->exists($reply->attachment));
        $this->assertSame('/ticket-file/' . $reply->id, $reply->attachment_url);

        $this->actingAs($owner, 'customer')->get($reply->attachment_url)->assertOk();
        $this->actingAs($stranger, 'customer')->get($reply->attachment_url)->assertNotFound();
        $admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->actingAs($admin, 'web')->withSession(['branch' => $branch])->get($reply->attachment_url)->assertOk();

        // an internal note's attachment never reaches the portal
        $reply->update(['is_internal' => true]);
        $this->actingAs($owner, 'customer')->get($reply->attachment_url)->assertNotFound();

        Storage::disk(Upload::PRIVATE_DISK)->delete($reply->attachment);
        Ticket::whereKey($id)->delete();
    }
}
