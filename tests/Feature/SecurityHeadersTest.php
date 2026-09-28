<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

// Security headers on web responses; the CSP nonce reaches the inline script of the page.
class SecurityHeadersTest extends TestCase
{
    use DatabaseTransactions;

    private function page()
    {
        return $this->actingAs(User::where('role', 'Superadmin')->firstOrFail(), 'web')
            ->withSession(['branch' => Branch::firstOrFail()])->get('/isp/connections');
    }

    public function test_html_pages_get_a_csp_with_a_nonce_used_by_the_inline_script(): void
    {
        $response = $this->page()->assertOk();
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9+\/=]+)'/", $csp);
        preg_match("/'nonce-([^']+)'/", $csp, $m);
        $this->assertStringContainsString('<script nonce="' . $m[1] . '">', $response->getContent());
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);

        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        preg_match("/'nonce-([^']+)'/", (string) $this->page()->headers->get('Content-Security-Policy'), $second);
        $this->assertNotSame($m[1], $second[1], 'a new nonce on every request');
    }

    public function test_hsts_only_over_https_and_json_gets_no_csp(): void
    {
        $this->page()->assertHeaderMissing('Strict-Transport-Security');
        $this->actingAs(User::where('role', 'Superadmin')->firstOrFail(), 'web')->withSession(['branch' => Branch::firstOrFail()])
            ->get('https://localhost/isp/connections')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $json = $this->actingAs(User::where('role', 'Superadmin')->firstOrFail(), 'web')->withSession(['branch' => Branch::firstOrFail()])
            ->postJson('/isp/get-connections');
        $json->assertHeaderMissing('Content-Security-Policy')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_policy_can_be_report_only_or_off(): void
    {
        config(['isp.csp' => 'report']);
        $this->page()->assertHeaderMissing('Content-Security-Policy')->assertHeader('Content-Security-Policy-Report-Only');
        config(['isp.csp' => 'off']);
        $this->page()->assertHeaderMissing('Content-Security-Policy')->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }
}
