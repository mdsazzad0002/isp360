<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

// Audit H4: opens every staff page (GET route without parameters) as the Superadmin. On a freshly
// migrated database this catches a page that reads a column or table the migrations never create.
class PageSmokeTest extends TestCase
{
    use DatabaseTransactions;

    // pages that end the session, leave the app or stream a download
    private const SKIP = ['logout', 'switch-back', 'horizon', 'reseller/', 'customer-portal/', 'two-factor/', 'license', 'subscription', 'clear-cache', 'export', 'manifest.webmanifest', 'sanctum', 'up', '_ignition'];

    public function test_every_staff_page_opens(): void
    {
        $admin = User::where('role', 'Superadmin')->firstOrFail();
        $branch = Branch::firstOrFail();

        $uris = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('GET', $r->methods()) && ! str_contains($r->uri(), '{'))
            ->map(fn ($r) => $r->uri())
            ->reject(fn ($uri) => $uri === '/' || collect(self::SKIP)->contains(fn ($skip) => str_starts_with($uri, $skip) || str_contains($uri, $skip)))
            ->unique()->values();
        $this->assertGreaterThan(50, $uris->count());

        $broken = [];
        foreach ($uris as $uri) {
            $status = $this->actingAs($admin, 'web')->withSession(['branch' => $branch])->get('/' . $uri)->getStatusCode();
            if ($status >= 500) {
                $broken[] = "{$uri} ({$status})";
            }
        }
        $this->assertSame([], $broken, 'Pages that fail to open');
    }

    // The list / report data behind those pages (POST .../get-*), called with no filters.
    public function test_every_staff_data_endpoint_answers(): void
    {
        $admin = User::where('role', 'Superadmin')->firstOrFail();
        $branch = Branch::firstOrFail();

        $uris = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('POST', $r->methods()) && ! str_contains($r->uri(), '{') && str_contains($r->uri(), 'get-'))
            ->map(fn ($r) => $r->uri())
            ->reject(fn ($uri) => collect(['reseller/', 'customer-portal/'])->contains(fn ($skip) => str_starts_with($uri, $skip)))
            ->unique()->values();
        $this->assertGreaterThan(40, $uris->count());

        $broken = [];
        foreach ($uris as $uri) {
            $response = $this->actingAs($admin, 'web')->withSession(['branch' => $branch])->postJson('/' . $uri, []);
            if ($response->getStatusCode() >= 500) {
                $broken[] = "{$uri} ({$response->getStatusCode()}): " . mb_substr((string) ($response->json('message') ?? ''), 0, 160);
            }
        }
        $this->assertSame([], $broken, 'Data endpoints that fail');
    }
}
