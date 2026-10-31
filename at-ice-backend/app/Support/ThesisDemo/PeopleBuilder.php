<?php

namespace App\Support\ThesisDemo;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// Fictional employees and, for positions that have one, their login. Codes
// continue the real EMP-#### sequence; emails follow the app's own pattern
// (last name + employee number) on a reserved, non-deliverable domain.
class PeopleBuilder
{
    public function __construct(private Context $c) {}

    // Resolved hire / regularization / end dates per code.
    public function dates(): array
    {
        $c = $this->c;
        $out = [];
        foreach (Roster::people() as $code => $p) {
            [$hired, $reg] = [$p[9], $p[10]];
            $end = null;
            $deactivated = null;
            switch ($hired) {
                case 'rel:prob':
                    // first paid period that starts less than 6 months before the as-of date
                    $limit = $c->asOf->subMonths(6)->addDay()->toDateString();
                    $hired = collect($c->paid)->first(fn ($x) => $x['start'] >= $limit)['start'];
                    break;
                case 'rel:reg13':
                    $reg = $c->ended[count($c->ended) - 4]['start'];
                    $hired = $c->date($reg)->subMonths(6)->toDateString();
                    break;
                case 'rel:mid15':
                    $hired = $c->date($c->paidFromEnd(2)['start'])->addDays(2)->toDateString();
                    break;
                case 'rel:mid16':
                    $hired = $c->date($c->paidFromEnd(1)['start'])->addDays(9)->toDateString();
                    break;
                case 'rel:future':
                    $hired = $c->asOf->addDays(7)->toDateString();
                    break;
                case 'rel:old':
                    $hired = $c->asOf->subYears(4)->startOfWeek()->toDateString();
                    $reg = $c->date($hired)->addMonths(6)->toDateString();
                    $end = $c->asOf->subYears(2)->subMonths(7)->toDateString();
                    $deactivated = $end;
                    break;
            }
            if ($code === 'E17') {
                // last day: the Friday of the second week of the last paid period
                $end = $c->date($c->paidFromEnd(1)['start'])->addDays(11)->toDateString();
                $deactivated = $end;
            }
            $out[$code] = ['hired' => $hired, 'regularized' => $reg, 'end' => $end, 'deactivated' => $deactivated];
        }

        return $out;
    }

    public function build(): void
    {
        $c = $this->c;
        $dates = $this->dates();
        $next = $this->nextCodeNumber();

        foreach (Roster::people() as $code => $p) {
            [$first, $mi, $last, $sex, $position, $status, $shift, $basis, $rate] = $p;
            $d = $dates[$code];
            $empKey = "employee:{$code}";

            if ($id = $c->m->id($empKey)) {
                $c->emp[$code] = $id;
                $c->user[$code] = DB::table('employees')->where('id', $id)->value('user_id');

                continue;
            }

            $number = $next++;
            $empCode = 'EMP-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
            $created = $c->stamp($d['hired'] <= $c->asOf->toDateString() ? $d['hired'] : $c->asOf->subDays(3)->toDateString(), '08:00:00');
            $active = $d['deactivated'] === null;

            $userId = null;
            if (Roster::hasLogin($position)) {
                [$role, $sub] = Roster::role($position);
                $email = preg_replace('/[^a-z]/', '', strtolower(Str::ascii($last))).'.'.$number.'@'.$c->domain;
                $password = Str::password(16, symbols: false);
                $userId = $c->m->insert("user:{$code}", 'users', [
                    'name' => "{$first} {$last}",
                    'email' => $email,
                    'role' => $role,
                    'sub_role' => $sub,
                    'is_active' => $active,
                    'password' => Hash::make($password),
                    'must_change_password' => $code === 'E20',
                    'created_at' => $created,
                    'updated_at' => $created,
                ]);
                $c->credentials[$code] = [$email, $password];
            }

            [$start, $end] = $shift === 'night' ? [$c->nightStart, $c->nightEnd] : [$c->dayStart, $c->dayEnd];
            $birth = CarbonImmutable::create(1985 + Context::pick($code.'b', 0, 15), Context::pick($code.'m', 1, 12), Context::pick($code.'d', 1, 28))->toDateString();
            $id = $c->m->insert($empKey, 'employees', [
                'user_id' => $userId,
                'employee_id' => $empCode,
                'first_name' => $first,
                'middle_initial' => $mi,
                'last_name' => $last,
                'position' => $position,
                'department' => Roster::DEPARTMENTS[$position],
                'daily_rate' => $basis === 'daily' ? $rate : 0,
                'pay_basis' => $basis,
                'monthly_rate' => $basis === 'monthly' ? $rate : null,
                'date_hired' => $d['hired'],
                'employment_type' => 'regular',
                'employment_status' => $status,
                'regularized_at' => $status === 'regular' ? $d['regularized'] : null,
                'shift' => $shift,
                'shift_start' => $start,
                'shift_end' => $end,
                'street' => 'Purok '.Context::pick($code.'p', 1, 7),
                'barangay' => ['Tungkong Mangga', 'Muzon', 'Graceville', 'Gaya-gaya', 'Sapang Palay', 'Kaypian'][Context::pick($code.'g', 0, 5)],
                'city' => 'San Jose del Monte',
                'province' => 'Bulacan',
                'region' => 'Region III (Central Luzon)',
                'address' => null,
                'birth_date' => $birth,
                'sex' => $sex,
                'civil_status' => ['single', 'married'][Context::pick($code.'c', 0, 1)],
                'nationality' => 'Filipino',
                'education_level' => in_array($position, ['Guard', 'Bagger'], true) ? 'high_school' : 'senior_high',
                'is_active' => $active,
                'deactivated_at' => $d['deactivated'] ? $c->stamp($d['deactivated'], '18:30:00') : null,
                'deactivated_by' => $d['deactivated'] ? $c->hrUserId : null,
                'no_timeout_count' => 0,
                'created_at' => $created,
                'updated_at' => $created,
            ]);
            $c->emp[$code] = $id;
            $c->user[$code] = $userId;
        }
    }

    // One above the highest EMP-#### in use (real or demo), like the app does.
    private function nextCodeNumber(): int
    {
        $max = DB::table('employees')->where('employee_id', 'like', 'EMP-%')->pluck('employee_id')
            ->map(fn ($v) => (int) substr($v, 4))->max() ?? 0;

        return $max + 1;
    }
}
