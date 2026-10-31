<?php

namespace App\Support\ThesisDemo;

// The fictional employees. Each one exists for named scenarios (see
// THESIS-DEMO-COVERAGE.md) so a payroll result is easy to explain. Names are
// made up; no government numbers, phone numbers, plates or licenses are set.
class Roster
{
    public const DEPARTMENTS = [
        'Checker' => 'Operations', 'Salesman' => 'Sales', 'Maintenance' => 'Maintenance',
        'Driver' => 'Logistics', 'Guard' => 'Security', 'Bagger' => 'Production',
    ];

    // code => [first, mi, last, sex, position, status, shift, basis, rate, hired, regularized, scenarios]
    // hired / regularized: a Y-m-d, or a relative rule worked out in PeopleBuilder.
    public static function people(): array
    {
        return [
            'E01' => ['Ramil', 'D', 'Ocampo', 'male', 'Checker', 'regular', 'day', 'daily', 610, '2024-02-05', '2024-08-05', 'P-01 perfect REG/DAY; non-taxable rice allowance (P-35)'],
            'E02' => ['Jerome', 'B', 'Villaflor', 'male', 'Checker', 'regular', 'night', 'daily', 610, '2024-05-06', '2024-11-06', 'P-02 perfect REG/NIGHT; overnight OT (P-26)'],
            'E03' => ['Kristine', 'A', 'Manalastas', 'female', 'Maintenance', 'probationary', 'day', 'daily', 600, 'rel:prob', null, 'P-03 perfect PROB/DAY; leave filing refused (L-09)'],
            'E04' => ['Arnel', 'C', 'Bustamante', 'male', 'Guard', 'probationary', 'night', 'daily', 590, 'rel:prob', null, 'P-04 perfect PROB/NIGHT; attendance only, no login (K-16)'],
            'E05' => ['Rodel', 'P', 'Macaraeg', 'male', 'Driver', 'regular', 'day', 'daily', 650, '2023-09-04', '2024-03-04', 'Driver of delivery history (D-01); performance published (H-04)'],
            'E06' => ['Danilo', 'R', 'Espiritu', 'male', 'Driver', 'regular', 'day', 'daily', 650, '2024-01-08', '2024-07-08', 'Driver on approved leave on the as-of day (L-18); reassigned-from driver (D-05)'],
            'E07' => ['Marvin', 'T', 'Cayabyab', 'male', 'Salesman', 'regular', 'day', 'daily', 620, '2024-03-04', '2024-09-04', 'Salesman who confirms most history deliveries (D-01, D-14)'],
            'E08' => ['Liza', 'M', 'Sarmiento', 'female', 'Checker', 'regular', 'day', 'daily', 610, '2024-07-01', '2025-01-01', 'Late by minutes (P-05); habitual tardiness memo to acknowledge (M-07, M-09)'],
            'E09' => ['Edgar', 'S', 'Tolentino', 'male', 'Bagger', 'regular', 'day', 'daily', 570, '2023-11-06', '2024-05-06', 'Late by hours (P-06); half-day late (P-07, defect D1); zero-tolerance NTE (M-08)'],
            'E10' => ['Jayson', 'L', 'Mercado', 'male', 'Salesman', 'regular', 'day', 'daily', 620, '2024-04-01', '2024-10-01', 'Absences (P-08); missing Time Out (P-10, D2); early out, no undertime (T-14); draft memo to cancel (M-11)'],
            'E11' => ['Grace', 'V', 'Dimaculangan', 'female', 'Checker', 'regular', 'day', 'monthly', 40000, '2023-06-05', '2023-12-05', 'Monthly basis (P-09); tax (P-34); SSS cap (P-33); taxable incentive (P-35)'],
            'E12' => ['Angelica', 'F', 'Rivera', 'female', 'Checker', 'regular', 'day', 'daily', 610, '2024-06-03', '2024-12-03', 'Leave: SL paid, SL unpaid, VL, weekend VL (D3), half day, EL hours, pending VL (L-01..L-17)'],
            'E13' => ['Paolo', 'N', 'Valdez', 'male', 'Salesman', 'regular', 'day', 'daily', 620, 'rel:reg13', 'rel:reg13', 'Recently regularized (P-30); not enough credits (L-05); rejected leave (L-07); unpaid EL hours (P-16)'],
            'E14' => ['Ronaldo', 'G', 'Pascual', 'male', 'Checker', 'regular', 'day', 'daily', 610, '2023-08-07', '2024-02-07', 'Holidays worked (P-17, P-20); OT approved / not approved (P-23, P-24, P-25)'],
            'E15' => ['Mark Anthony', 'J', 'Soriano', 'male', 'Checker', 'probationary', 'day', 'daily', 610, 'rel:mid15', null, 'Hired mid-period, daily (P-28)'],
            'E16' => ['Camille', 'D', 'Ventura', 'female', 'Maintenance', 'probationary', 'day', 'monthly', 18000, 'rel:mid16', null, 'Hired mid-period, monthly (P-29)'],
            'E17' => ['Benjie', 'A', 'Navarro', 'male', 'Salesman', 'regular', 'day', 'daily', 620, '2025-01-06', '2025-07-06', 'Employment ended: final pay, login refused, no later attendance (P-31, A-03, D5)'],
            'E18' => ['Ernesto', 'P', 'Lumibao', 'male', 'Bagger', 'regular', 'day', 'daily', 570, '2024-09-02', '2025-03-02', 'Negative net carried over (P-37, P-38)'],
            'E19' => ['Ricardo', 'M', 'Samonte', 'male', 'Driver', 'regular', 'day', 'daily', 650, '2025-03-03', '2025-09-03', 'Memo ladder to NTE, old offense outside the window, live auto memo (M-01..M-05)'],
            'E20' => ['Joanna', 'R', 'Castillo', 'female', 'Checker', 'probationary', 'day', 'daily', 610, 'rel:future', null, 'Starts after the as-of date: kiosk "not started" (K-14); first-login password change (A-02)'],
            'E21' => ['Wilfredo', 'T', 'Ramos', 'male', 'Checker', 'regular', 'day', 'daily', 560, 'rel:old', 'rel:old', 'Inactive past the retention period (H-05)'],
            'E22' => ['Noel', 'C', 'Fajardo', 'male', 'Checker', 'regular', 'night', 'daily', 610, '2024-10-07', '2025-04-07', 'Night shift: Time In after midnight (D4), shift crossing a cut-off (T-09, T-10)'],
            'E23' => ['Arturo', 'B', 'Galang', 'male', 'Maintenance', 'regular', 'day', 'daily', 600, '2024-01-15', '2024-07-15', 'Stock-out requests and projects (I-04, I-05)'],
        ];
    }

    public static function hasLogin(string $position): bool
    {
        return ! in_array($position, ['Guard', 'Bagger'], true);
    }

    // users.role and users.sub_role for a position
    public static function role(string $position): array
    {
        return $position === 'Driver' ? ['driver', null] : ['employee', strtolower($position)];
    }

    // face_data.role / attendance kiosk role
    public static function kioskRole(string $position): string
    {
        return strtolower($position);
    }
}
