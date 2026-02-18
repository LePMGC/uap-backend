<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Core\UserManagement\Models\User; // Ensure the correct namespace
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Create the System Administrator
        $adminUser = User::create([
            'username'     => 'admin_uap',
            'name'         => 'System Administrator',
            'email'        => 'admin@telecom.internal',
            'phone_number' => '+242000000001',
            'password'     => Hash::make('Admin@2026!'),
        ]);
        
        // Assign the admin role
        $adminUser->assignRole('admin');

        // 2. Create the First Operator (Non-Admin)
        $operatorUser = User::create([
            'username'     => 'operator_01',
            'name'         => 'NOC Operator One',
            'email'        => null,
            'phone_number' => '+242000000002',
            'password'     => Hash::make('Operator@2026!'),
        ]);

        // Assign the operator role
        $operatorUser->assignRole('operator');
    }
}