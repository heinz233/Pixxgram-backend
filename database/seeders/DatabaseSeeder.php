<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\Role;
use App\Models\User;
use App\Models\PhotographerProfile;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Roles ─────────────────────────────────────────────────────
        // IMPORTANT: names must be lowercase and match exactly what
        // the Vue router checks: 'admin', 'photographer', 'client'
        $admin        = Role::create(['name' => 'admin',        'description' => 'Platform administrator']);
        $photographer = Role::create(['name' => 'photographer', 'description' => 'Photographer user']);
        $client       = Role::create(['name' => 'client',       'description' => 'Client user']);

        // ── Admin account ──────────────────────────────────────────────
        User::create([
            'name'              => 'Admin',
            'email'             => '233ateng@gmail.com',
            'phoneNumber'       => '0781544283',
            'role_id'           => $admin->id,
            'password'          => Hash::make('Qwerty1234'),
            'is_active'         => true,
            'status'            => 'active',
            'email_verified_at' => now(),
        ]);

        // ── Photographer account ───────────────────────────────────────
        $photographer_user = User::create([
            'name'              => 'Heinz Ateng',
            'email'             => 'ateng.heinz@gmail.com',
            'phoneNumber'       => '0701585836',
            'role_id'           => $photographer->id,
            'password'          => Hash::make('Qwerty1234'),
            'is_active'         => true,
            'status'            => 'active',
            'email_verified_at' => now(),
        ]);

        // Every photographer needs a profile record
        PhotographerProfile::create([
            'user_id'             => $photographer_user->id,
            'bio'                 => 'Professional photographer based in Nairobi.',
            'location'            => 'Nairobi',
            'hourly_rate'         => 2500,
            'subscription_status' => 'inactive',
            'average_rating'      => 0,
            'total_ratings'       => 0,
        ]);

        // ── Client account ─────────────────────────────────────────────
        User::create([
            'name'              => 'Test Client',
            'email'             => 'client@pixxgram.com',
            'phoneNumber'       => '0700000000',
            'role_id'           => $client->id,
            'password'          => Hash::make('Qwerty1234'),
            'is_active'         => true,
            'status'            => 'active',
            'email_verified_at' => now(),
        ]);
    }
}