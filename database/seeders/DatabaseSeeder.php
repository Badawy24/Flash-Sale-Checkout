<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->seedUsers();
        $this->seedProducts();
    }

    public function seedProducts(): void
    {
        Product::create([
            'name' => 'Mobile Phone',
            'price' => 1000,
            'stock' => 50,
            'reserved' => 0,
        ]);
    }

    public function seedUsers(): void
    {
        User::create([
            'name' => 'badawy',
            'email' => 'badawy@mail.com',
            'password' => '123456789',
        ]);

        User::factory()->count(5)->create();
    }
}
