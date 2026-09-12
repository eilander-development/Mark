<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_val_import_preview_is_gone(): void
    {
        $this->getJson('/api/import/val')->assertNotFound();
    }

    public function test_val_import_is_gone(): void
    {
        $this->postJson('/api/import/val', ['confirm' => true])->assertNotFound();
    }
}
