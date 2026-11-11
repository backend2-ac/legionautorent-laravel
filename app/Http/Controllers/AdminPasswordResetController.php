<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;

/**
 * Lets the owner set a new admin password from a signed link issued by `legion:admin-link` on the server.
 *
 * The link carries a fingerprint of the current password hash, so it stops working as soon as the password changes.
 */
class AdminPasswordResetController extends Controller
{
    /**
     * A relative signed path, so the signature does not depend on the proxy's scheme or host.
     */
    public static function link(User $user, int $minutes): string
    {
        return URL::temporarySignedRoute('admin.password-reset', now()->addMinutes($minutes), ['user' => $user->id, 'fingerprint' => self::fingerprint($user)], absolute: false);
    }

    public function show(Request $request, User $user): Response
    {
        $this->guard($request, $user);

        return response()->view('admin-password-reset', ['user' => $user, 'action' => $request->getRequestUri()])->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->guard($request, $user);
        $request->validate(['password' => ['required', 'string', 'min:12', 'max:200', 'confirmed']], [
            'password.min' => 'Пароль должен быть не короче 12 символов.',
            'password.confirmed' => 'Пароли не совпадают.',
            'password.required' => 'Введите новый пароль.',
        ]);
        $user->forceFill(['password' => Hash::make($request->string('password')->toString())])->save();

        return redirect('/'.config('legion.admin_path').'/login')->with('status', 'password-reset');
    }

    private function guard(Request $request, User $user): void
    {
        abort_unless($user->is_staff && $user->is_active, 404);
        abort_unless(hash_equals(self::fingerprint($user), (string) $request->route('fingerprint')), 410, 'Ссылка уже использована. Попросите новую.');
    }

    private static function fingerprint(User $user): string
    {
        return substr(hash_hmac('sha256', (string) $user->password, (string) config('app.key')), 0, 24);
    }
}
