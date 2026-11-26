<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Admin and demo accounts. Safe to run twice: existing users are left alone.
 */
class DatabaseSeeder extends Seeder
{
    /** 20 USD of fictitious credit for the demo user. */
    public const DEMO_CREDIT_NANO = 20_000_000_000;

    public function run(LedgerService $ledger): void
    {
        $this->account('admin', 'Admin', isAdmin: true);
        $demo = $this->account('demo', 'Demo user', isAdmin: false);

        if ($demo->wasRecentlyCreated) {
            $ledger->topUp($demo, self::DEMO_CREDIT_NANO);
        }
    }

    private function account(string $key, string $name, bool $isAdmin): User
    {
        $email = config("app.seed.{$key}_email");
        $password = config("app.seed.{$key}_password");

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            throw new RuntimeException('Set SEED_'.strtoupper($key).'_EMAIL and SEED_'.strtoupper($key).'_PASSWORD in .env before seeding.');
        }

        $user = User::firstOrNew(['email' => $email]);

        // Open registration: someone may have taken the admin email first. Never promote silently.
        if ($isAdmin && $user->exists && ! $user->is_admin) {
            throw new RuntimeException("{$email} is already registered and is not an admin. Fix it by hand before seeding.");
        }

        if (! $user->exists) {
            $user->forceFill([
                'name' => $name,
                'password' => $password, // hashed by the model cast
                'is_admin' => $isAdmin,
                'email_verified_at' => now(),
            ])->save();
        }

        return $user;
    }
}
