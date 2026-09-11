<?php

namespace Tests\Unit;

use App\Services\Periodization;
use PHPUnit\Framework\TestCase;

class PeriodizationTest extends TestCase
{
    private Periodization $periodization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->periodization = new Periodization;
    }

    public function test_week_seven_is_deload(): void
    {
        $this->assertFalse($this->periodization->isDeloadWeek(1));
        $this->assertFalse($this->periodization->isDeloadWeek(6));
        $this->assertTrue($this->periodization->isDeloadWeek(7));
        $this->assertTrue($this->periodization->isDeloadWeek(14));
    }

    public function test_epley_one_rm(): void
    {
        $this->assertSame(80, $this->periodization->calculate1Rm(80, 1));
        $this->assertSame(107, $this->periodization->calculate1Rm(80, 10));
        $this->assertNull($this->periodization->calculate1Rm(0, 8));
    }

    public function test_week_one_keeps_previous_max(): void
    {
        $this->assertSame(80.0, $this->periodization->advisedWeight(80, 1, 2.5, 'weekly'));
    }

    public function test_weekly_overload_adds_increment(): void
    {
        $this->assertSame(82.5, $this->periodization->advisedWeight(80, 3, 2.5, 'weekly'));
    }

    public function test_easy_hit_adds_double_increment(): void
    {
        $this->assertSame(85.0, $this->periodization->advisedWeight(80, 3, 2.5, 'weekly', 'easy', true));
        $this->assertSame(85.0, $this->periodization->advisedWeight(80, 2, 2.5, 'biweekly', 'easy', true));
    }

    public function test_missed_max_set_drops_one_increment(): void
    {
        $this->assertSame(77.5, $this->periodization->advisedWeight(80, 3, 2.5, 'weekly', 'max', false));
        $this->assertSame(80.0, $this->periodization->advisedWeight(80, 3, 2.5, 'weekly', 'good', false));
    }

    public function test_target_rpe_follows_the_mesocycle(): void
    {
        $this->assertSame(7.0, $this->periodization->targetRpe(1));
        $this->assertSame(8.0, $this->periodization->targetRpe(3));
        $this->assertSame(9.0, $this->periodization->targetRpe(6));
        $this->assertSame(6.5, $this->periodization->targetRpe(7));
    }

    public function test_deload_rounds_seventy_percent_to_the_increment(): void
    {
        $this->assertSame(55.0, $this->periodization->advisedWeight(80, 7, 2.5, 'weekly'));
        $this->assertSame(56.0, $this->periodization->advisedWeight(80, 7, 2.0, 'weekly'));
    }

    public function test_last_heavy_week_skips_deload(): void
    {
        $this->assertSame(6, $this->periodization->lastHeavyWeek(7));
    }

    public function test_biweekly_holds_on_even_weeks(): void
    {
        $this->assertSame(80.0, $this->periodization->advisedWeight(80, 2, 2.5, 'biweekly'));
        $this->assertSame(82.5, $this->periodization->advisedWeight(80, 3, 2.5, 'biweekly'));
    }

    public function test_target_achieved_requires_all_working_sets(): void
    {
        $sets = [
            (object) ['completed' => true, 'weight' => 80, 'reps' => 8],
            (object) ['completed' => true, 'weight' => 80, 'reps' => 8],
            (object) ['completed' => true, 'weight' => 80, 'reps' => 8],
        ];
        $this->assertTrue($this->periodization->targetAchieved($sets, 8, 2, false));

        $sets[2]->reps = 6;
        $this->assertFalse($this->periodization->targetAchieved($sets, 8, 2, false));
    }
}
