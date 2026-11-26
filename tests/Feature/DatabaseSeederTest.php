<?php

use App\Models\LedgerEntry;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    config([
        'app.seed.admin_email' => 'admin@pixelforge.test',
        'app.seed.admin_password' => 'admin-secret-1',
        'app.seed.demo_email' => 'demo@pixelforge.test',
        'app.seed.demo_password' => 'demo-secret-1',
    ]);
});

it('creates the admin and a demo user with starting credit, once', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $admin = User::where('email', 'admin@pixelforge.test')->sole();
    $demo = User::where('email', 'demo@pixelforge.test')->sole();

    expect($admin->is_admin)->toBeTrue()
        ->and($demo->is_admin)->toBeFalse()
        ->and(Hash::check('demo-secret-1', $demo->password))->toBeTrue()
        ->and($demo->balance_nano)->toBe(DatabaseSeeder::DEMO_CREDIT_NANO)
        ->and(LedgerEntry::where('user_id', $demo->id)->where('type', 'topup')->count())->toBe(1);
});

it('refuses to seed without passwords from the environment', function () {
    config(['app.seed.demo_password' => null]);

    $this->seed(DatabaseSeeder::class);
})->throws(RuntimeException::class);

it('refuses to seed when the admin email belongs to a regular account', function () {
    User::factory()->create(['email' => 'admin@pixelforge.test', 'is_admin' => false]);

    $this->seed(DatabaseSeeder::class);
})->throws(RuntimeException::class, 'not an admin');
