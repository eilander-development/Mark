<?php

namespace Tests\Unit;

use App\Services\Periodization;
use App\Services\StrengthBenchmark;
use PHPUnit\Framework\TestCase;

class StrengthBenchmarkTest extends TestCase
{
    public function test_barbell_bench_uses_marker_norms_not_bodyweight_ratio(): void
    {
        $benchmark = new StrengthBenchmark(new Periodization);
        $row = $benchmark->for('Barbell Bench Press', [
            'birthYear' => 1984,
            'bodyWeightKg' => 82,
        ], 2026);

        $this->assertSame(75, $row['intermediate']);
        $this->assertSame(70, $row['suggestedStart']);
        $this->assertSame(80, $row['cycleGoal']);
        $this->assertFalse($row['isBodyweight']);
        $this->assertSame('kg', $row['unit']);
    }

    public function test_incline_dumbbell_is_per_hand(): void
    {
        $benchmark = new StrengthBenchmark(new Periodization);
        $row = $benchmark->for('Incline Dumbbell Press', [
            'birthYear' => 1984,
            'bodyWeightKg' => 82,
        ], 2026);

        $this->assertTrue($row['isPerDumbbell']);
        $this->assertSame(26, $row['intermediate']);
        $this->assertSame(24, $row['suggestedStart']);
    }
}
