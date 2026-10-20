<?php

namespace Database\Seeders;

use App\Services\ProgramLibrary;
use Illuminate\Database\Seeder;

class ProgramVariantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(ProgramLibrary::class)->seedVariants();
    }
}
