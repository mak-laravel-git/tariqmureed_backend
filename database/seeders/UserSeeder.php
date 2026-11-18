<?php

namespace Database\Seeders;

use App\Enums\RoleEnum;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Seeds local development accounts. Never run against production: the
     * passwords below are public.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('UserSeeder skipped in production.');

            return;
        }

        foreach ($this->users() as $account) {
            $user = User::firstOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'password' => Hash::make($account['password']),
                    'email_verified_at' => now(),
                ]
            );

            $user->syncRoles([$account['role']]);
        }

        $this->command?->info('Users: '.count($this->users()).' seeded.');
    }

    /**
     * @return array<int, array{name: string, email: string, password: string, role: string}>
     */
    private function users(): array
    {
        return [
            [
                'name' => 'Super Admin',
                'email' => 'superadmin@dev.com',
                'password' => 'superadmin@dev.com',
                'role' => RoleEnum::SUPER_ADMIN->value,
            ],
            [
                'name' => 'Admin',
                'email' => 'admin@dev.com',
                'password' => 'admin@dev.com',
                'role' => RoleEnum::ADMIN->value,
            ],
            [
                'name' => 'User',
                'email' => 'user@dev.com',
                'password' => 'user@dev.com',
                'role' => RoleEnum::USER->value,
            ],
        ];
    }
}
