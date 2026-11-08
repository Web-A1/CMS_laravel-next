<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicHeaderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => 'http://localhost:3300']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_staff_pages_replace_the_topbar_and_keep_the_native_user_menu(): void
    {
        foreach (['admin_full', 'admin_limited', 'manager', 'teacher'] as $role) {
            $response = $this->actingAs(User::factory()->create(['role' => $role]), 'web')
                ->get('/admin/settings')->assertOk();
            $response->assertSee('data-ui="admin.publicHeader"', false)
                ->assertSee('Настройки')->assertDontSee('На сайт')
                ->assertSee('http://localhost:3300/admin/logout', false)
                ->assertSee('fi-theme-switcher', false)
                ->assertSee('theme-changed', false)
                ->assertDontSee('class="fi-topbar"', false);
            $html = $response->getContent();
            $this->assertSame(1, substr_count($html, 'data-ui="admin.publicHeader"'));
            $this->assertSame(1, substr_count($html, 'class="fi-user-menu-trigger"'));
            $this->assertMatchesRegularExpression('/<form[^>]+action="http:\/\/localhost:3300\/admin\/logout"[^>]+method="post"/s', $html);
            $dom = new DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new DOMXPath($dom);
            $this->assertSame(0, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " fi-user-menu ")]//a')->length);
            $this->assertSame(0, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " fi-user-menu ")]//form')->length);
            $this->assertSame(1, $xpath->query('//form[@data-ui="header.logoutForm"]//input[@name="_token"]')->length);
            $this->assertSame(1, $xpath->query('//button[@data-ui="header.themeTrigger"]//svg[@viewbox="0 0 16 16"]')->length);
            $this->assertSame(0, $xpath->query('//button[@data-ui="header.themeTrigger"]//img')->length);
            $this->assertLessThan(strpos($html, '<span class="cms-header-name"'), strpos($html, 'class="fi-user-menu-trigger"'));
        }
    }

    public function test_public_destinations_stay_in_sync_with_site_header(): void
    {
        $html = $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web')
            ->get('/admin/settings')->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($dom);
        $destinations = [];
        foreach ($xpath->query('//nav[@data-ui="header.desktopNav"]//a') as $link) {
            $destinations[$link->getAttribute('href')] = trim($link->textContent);
            $this->assertFalse($link->hasAttribute('wire:navigate'));
        }

        $source = file_get_contents(base_path('../frontend/src/components/header/SiteHeader.tsx'));
        preg_match('/const menuItems = (.*?);/s', $source, $menu);
        preg_match('/const subjectMenuItems = (.*?) as const;/s', $source, $subjects);
        preg_match_all('/href: "([^"]+)", label: "([^"]+)"/', $menu[1].$subjects[1], $matches, PREG_SET_ORDER);
        $expected = [];
        foreach ($matches as $match) {
            $expected[$match[1]] = $match[2];
        }
        $this->assertCount(21, $expected);
        ksort($expected);
        ksort($destinations);
        $this->assertSame($expected, $destinations);
    }
}
