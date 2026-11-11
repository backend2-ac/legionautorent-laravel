<?php

namespace App\Console\Commands;

use App\Http\Controllers\AdminPasswordResetController;
use App\Models\User;
use Illuminate\Console\Command;

class AdminPasswordLink extends Command
{
    protected $signature = 'legion:admin-link {username? : Administrator login; omit to list administrators} {--minutes=30 : How long the link stays valid}';

    protected $description = 'List administrators or issue a one-time link where the owner sets a new admin password themselves';

    public function handle(): int
    {
        $username = $this->argument('username');
        if ($username === null) {
            $users = User::where('is_staff', true)->orderBy('id')->get(['id', 'username', 'is_active', 'is_superuser', 'last_login']);
            $this->table(['Логин', 'Активен', 'Владелец', 'Последний вход'], $users->map(fn (User $user): array => [
                $user->username, $user->is_active ? 'да' : 'нет', $user->is_superuser ? 'да' : 'нет', $user->last_login ?? '—',
            ])->all());

            return self::SUCCESS;
        }

        $user = User::where('username', $username)->where('is_staff', true)->where('is_active', true)->first();
        if (! $user) {
            $this->error('Active administrator not found: '.$username);

            return self::FAILURE;
        }
        $minutes = max(5, min(120, (int) $this->option('minutes')));
        $this->info('One-time link for '.$user->username.', valid for '.$minutes.' minutes:');
        $this->line(rtrim((string) config('app.url'), '/').AdminPasswordResetController::link($user, $minutes));
        $this->line('If the domain above is wrong, open the same path on the site address.');

        return self::SUCCESS;
    }
}
