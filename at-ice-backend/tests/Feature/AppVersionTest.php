<?php

namespace Tests\Feature;

use Tests\TestCase;

// GET /api/app-version tells the Android app which build is the newest one offered.
class AppVersionTest extends TestCase
{
    private string $dir;

    /** @var array<string, string> files already published here (a real build): put back after each test */
    private array $kept = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = public_path('downloads');
        @mkdir($this->dir, 0777, true);
        foreach (['app-version.json', 'AT-ICE.apk'] as $name) {
            if (is_file($this->dir.'/'.$name)) {
                $this->kept[$name] = (string) file_get_contents($this->dir.'/'.$name);
            }
        }
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        foreach ($this->kept as $name => $content) {
            file_put_contents($this->dir.'/'.$name, $content);
        }
        parent::tearDown();
    }

    private function clean(): void
    {
        @unlink($this->dir.'/app-version.json');
        @unlink($this->dir.'/AT-ICE.apk');
    }

    public function test_it_reports_no_build_when_no_app_is_published(): void
    {
        $this->getJson('/api/app-version')->assertOk()->assertExactJson(['build' => 0]);
    }

    public function test_it_reports_no_build_when_the_apk_file_is_missing(): void
    {
        file_put_contents($this->dir.'/app-version.json', json_encode(['build' => 1790000000, 'name' => '2026-11-11']));

        $this->getJson('/api/app-version')->assertOk()->assertExactJson(['build' => 0]);
    }

    public function test_it_reports_the_published_build_and_the_apk_address(): void
    {
        file_put_contents($this->dir.'/app-version.json', json_encode(['build' => 1790000000, 'name' => '2026-11-11']));
        file_put_contents($this->dir.'/AT-ICE.apk', 'apk');

        $this->getJson('/api/app-version')
            ->assertOk()
            ->assertJsonPath('build', 1790000000)
            ->assertJsonPath('name', '2026-11-11')
            ->assertJsonPath('apk_url', url('/api/app-download'));
    }

    public function test_the_apk_is_sent_as_an_android_package_file_named_at_ice_apk(): void
    {
        file_put_contents($this->dir.'/AT-ICE.apk', 'apk');

        $response = $this->get('/api/app-download');

        $response->assertOk();
        $this->assertSame('application/vnd.android.package-archive', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('AT-ICE.apk', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_the_download_is_a_404_when_no_apk_is_published(): void
    {
        $this->getJson('/api/app-download')->assertNotFound();
    }

    public function test_a_broken_version_file_counts_as_no_build(): void
    {
        file_put_contents($this->dir.'/app-version.json', '{not json');
        file_put_contents($this->dir.'/AT-ICE.apk', 'apk');

        $this->getJson('/api/app-version')->assertOk()->assertExactJson(['build' => 0]);
    }
}
