<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Delivery;
use App\Models\DeliveryCrew;
use App\Models\Employee;
use App\Models\EmployeeMemo;
use App\Models\Expense;
use App\Models\PerformanceReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Date-only fields go out as plain Y-m-d. As a full timestamp, Manila midnight
// becomes 16:00 UTC of the day before, so pages that read the first 10
// characters showed yesterday (and an edited hire date moved back a day).
class DateOnlyFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_date_only_fields_keep_their_day_in_json(): void
    {
        $cases = [
            [Employee::class, 'date_hired'],
            [Attendance::class, 'date'],
            [Delivery::class, 'scheduled_date'],
            [Expense::class, 'expense_date'],
            [EmployeeMemo::class, 'incident_date'],
            [DeliveryCrew::class, 'date'],
            [PerformanceReview::class, 'period_start'],
            [PerformanceReview::class, 'period_end'],
        ];

        foreach ($cases as [$model, $field]) {
            $row = (new $model)->forceFill([$field => '2026-11-06']);

            $this->assertSame('2026-11-06', $row->toArray()[$field], "$model::$field");
        }
    }
}
