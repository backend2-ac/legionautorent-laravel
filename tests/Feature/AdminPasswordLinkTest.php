<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminPasswordResetController;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\CatalogueTestCase;

class AdminPasswordLinkTest extends CatalogueTestCase
{
    private function forgottenOwner(): User
    {
        return User::create(['username' => 'forgotten-owner', 'password' => Hash::make('old-password-123'), 'is_staff' => true, 'is_active' => true, 'is_superuser' => true]);
    }

    public function test_the_command_lists_administrators_without_passwords(): void
    {
        $owner = $this->forgottenOwner();

        $this->artisan('legion:admin-link')->expectsOutputToContain($owner->username)->doesntExpectOutputToContain($owner->password)->assertSuccessful();
    }

    public function test_the_owner_sets_a_new_password_once_through_the_link(): void
    {
        $owner = $this->forgottenOwner();
        $link = AdminPasswordResetController::link($owner, 30);

        $this->get($link)->assertOk()->assertSee('forgotten-owner')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->post($link, ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post($link, ['password' => 'brand-new-password-2026', 'password_confirmation' => 'brand-new-password-2026'])->assertRedirect('/admin/login');

        $this->assertTrue(Hash::check('brand-new-password-2026', $owner->fresh()->password));
        $this->get($link)->assertStatus(410);
        $this->post($link, ['password' => 'another-password-2026', 'password_confirmation' => 'another-password-2026'])->assertStatus(410);
        $this->assertTrue(Hash::check('brand-new-password-2026', $owner->fresh()->password));
    }

    public function test_unsigned_tampered_or_expired_links_are_rejected(): void
    {
        $owner = $this->forgottenOwner();
        $link = AdminPasswordResetController::link($owner, 30);

        $this->get('/admin-password/'.$owner->id.'/anything')->assertForbidden();
        $tampered = $this->get(str_replace('/'.$owner->id.'/', '/'.($owner->id + 1).'/', $link));
        $this->assertContains($tampered->getStatusCode(), [403, 404]);
        $this->travel(31)->minutes();
        $this->get($link)->assertForbidden();
    }
}
