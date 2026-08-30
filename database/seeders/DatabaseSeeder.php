<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

//        User::factory()->create([
//            'name' => 'Test User',
//            'email' => 'test@example.com',
//        ]);

        $this->call([
            PapeisSeeder::class,
            CidadeSeeder::class,
            EventoDemoSeeder::class,
            // So faz alguma coisa em ambiente local; nos demais, avisa e sai.
            AdminDemoSeeder::class,
        ]);
    }
}
