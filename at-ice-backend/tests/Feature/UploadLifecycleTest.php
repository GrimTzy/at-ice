<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryProof;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeMemo;
use App\Models\EmployeeMemoFile;
use App\Models\FaceData;
use App\Models\LeaveRequest;
use App\Models\PaymentProof;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

// Upload lifecycle. A database transaction does not undo
// file writes, so every path must clean up its own files. Each failure is injected with a model
// event that throws at one chosen step; the checks are: no partial record, no new file, and every
// file that existed before is untouched.
class UploadLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    private User $empUser;

    private Employee $emp;

    private User $custUser;

    private Customer $cust;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private const JPG = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        config(['app.debug' => false]);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 14)->setTime(10, 0));
        $this->hr = User::create(['name' => 'HR', 'email' => 'hr@atice.test', 'password' => 'secret-password', 'role' => 'hr', 'is_active' => true]);
        $this->empUser = User::create(['name' => 'Emp', 'email' => 'emp@atice.test', 'password' => 'secret-password', 'role' => 'employee', 'sub_role' => 'checker', 'is_active' => true]);
        $this->emp = Employee::create(['user_id' => $this->empUser->id, 'employee_id' => 'EMP-0001', 'first_name' => 'Emp', 'last_name' => 'A', 'position' => 'Checker', 'daily_rate' => 600, 'date_hired' => '2025-06-01', 'employment_type' => 'regular', 'shift' => 'day', 'is_active' => true, 'employment_status' => 'regular', 'regularized_at' => '2025-12-01']);
        $this->custUser = User::create(['name' => 'Cust', 'email' => 'cust@atice.test', 'password' => 'secret-password', 'role' => 'customer', 'is_active' => true]);
        $this->cust = Customer::create(['name' => 'Store', 'customer_code' => 'CUST-1', 'branch_code' => 'S-1', 'invoice_code' => 'SS', 'is_active' => true, 'user_id' => $this->custUser->id]);
    }

    // ---------------------------------------------------------------- helpers

    private function png(string $name = 'a.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    private function pdf(string $name = 'a.pdf', int $padKb = 0): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n".str_repeat('0', $padKb * 1024));
    }

    private function boom(): \Closure
    {
        return fn () => throw new RuntimeException('injected failure: secret-detail');
    }

    /** every file on both disks, with a hash, so "untouched" can be proved */
    private function snapshot(): array
    {
        $out = [];
        foreach (['local', 'public'] as $d) {
            foreach (Storage::disk($d)->allFiles() as $f) {
                $out["$d:$f"] = md5(Storage::disk($d)->get($f));
            }
        }

        return $out;
    }

    private function assertNothingChanged(array $before, string $why = ''): void
    {
        $this->assertSame($before, $this->snapshot(), "Files changed after a failed request. {$why}");
    }

    private function form(int $n = 1): array
    {
        return ['name' => 'Maria Reyes', 'phone' => '0917'.str_pad((string) (4000000 + $n), 7, '0', STR_PAD_LEFT), 'position_applied' => 'Driver', 'street' => 'Rizal St', 'barangay' => 'Muzon', 'barangay_code' => '031420007', 'city' => 'San Jose Del Monte', 'province' => 'Bulacan', 'region' => 'Region III – Central Luzon', 'consent' => true, 'license_number' => 'N01-23-456789', 'license_expiry' => now()->addYears(2)->toDateString()];
    }

    private function applicantWithFiles(): Applicant
    {
        $a = Applicant::create(['name' => 'Seed', 'phone' => '+639170000100', 'position_applied' => 'Driver', 'status' => 'pending', 'source' => 'qr']);
        Storage::disk('local')->put('resumes/seed.pdf', 'resume-bytes');
        Storage::disk('local')->put('applicant-documents/seed-photo.png', 'photo-bytes');
        Storage::disk('local')->put('applicant-documents/seed-id.png', 'id-bytes');
        $a->update(['resume_path' => 'resumes/seed.pdf', 'resume_name' => 'seed.pdf']);
        foreach (['seed-photo' => 'photo', 'seed-id' => 'other'] as $f => $type) {
            ApplicantDocument::create(['applicant_id' => $a->id, 'type' => $type, 'path' => "applicant-documents/{$f}.png", 'original_name' => "{$f}.png", 'mime' => 'image/png', 'size' => 10]);
        }

        return $a;
    }

    // ---------------------------------------------------------------- 1. public application

    /** @return array<string, array{string}> */
    public static function applyFailurePoints(): array
    {
        return ['applicant record' => ['applicant'], 'first document record' => ['doc1'], 'second document record' => ['doc2']];
    }

    #[DataProvider('applyFailurePoints')]
    public function test_a_failure_at_any_step_of_the_public_application_saves_nothing_and_leaves_no_file(string $point): void
    {
        Storage::disk('local')->put('resumes/existing.pdf', 'existing');
        $before = $this->snapshot();
        $n = 0;
        match ($point) {
            'applicant' => Applicant::creating($this->boom()),
            'doc1' => ApplicantDocument::creating(function () use (&$n) {
                if (++$n === 1) {
                    throw new RuntimeException('injected failure: secret-detail');
                }
            }),
            'doc2' => ApplicantDocument::creating(function () use (&$n) {
                if (++$n === 2) {
                    throw new RuntimeException('injected failure: secret-detail');
                }
            }),
        };

        $res = $this->post('/api/apply', $this->form() + ['resume' => $this->pdf('cv.pdf'), 'documents' => [$this->png('a.png'), $this->png('b.png')]], ['Accept' => 'application/json']);

        $res->assertStatus(500);
        $this->assertStringNotContainsString('secret-detail', $res->getContent());
        $this->assertSame(0, Applicant::count(), 'No half-saved application.');
        $this->assertSame(0, ApplicantDocument::count());
        $this->assertNothingChanged($before);
    }

    public function test_a_successful_public_application_keeps_every_file(): void
    {
        $this->post('/api/apply', $this->form(2) + ['resume' => $this->pdf('cv.pdf'), 'photo' => $this->png('me.png'), 'documents' => [$this->png('a.png')]], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(1, Applicant::count());
        $this->assertSame(2, ApplicantDocument::count());
        foreach (ApplicantDocument::pluck('path') as $p) {
            Storage::disk('local')->assertExists($p);
        }
        Storage::disk('local')->assertExists(Applicant::first()->resume_path);
    }

    // ---------------------------------------------------------------- aggregate size against the nginx limit

    public function test_files_that_are_too_big_together_are_refused_with_a_clear_message_before_anything_is_stored(): void
    {
        // each file is inside its own limit (5 MB resume, 10 MB others) but together they pass the 11 MB the proxy leaves
        $res = $this->post('/api/apply', $this->form(3) + ['resume' => $this->pdf('cv.pdf', 4000), 'photo' => $this->png('me.png')] + ['documents' => [$this->pdf('d.pdf', 4000), $this->pdf('e.pdf', 4000)]], ['Accept' => 'application/json']);

        $res->assertStatus(422)->assertJsonValidationErrors('documents');
        $this->assertStringContainsString('11 MB', $res->json('errors.documents.0'));
        $this->assertSame(0, Applicant::count());
        $this->assertSame([], $this->snapshot());
    }

    public function test_the_same_files_under_the_total_limit_are_accepted(): void
    {
        $this->post('/api/apply', $this->form(4) + ['resume' => $this->pdf('cv.pdf', 1000), 'documents' => [$this->pdf('d.pdf', 1000)]], ['Accept' => 'application/json'])->assertCreated();
    }

    // ---------------------------------------------------------------- 2. applicant resume: create, replace, remove

    public function test_hr_creating_an_applicant_with_a_resume_leaves_no_file_when_the_record_fails(): void
    {
        Sanctum::actingAs($this->hr);
        $before = $this->snapshot();
        Applicant::creating($this->boom());

        $this->post('/api/applicants', ['name' => 'New One', 'position_applied' => 'Driver', 'phone' => '09171230001', 'resume' => $this->pdf('cv.pdf')], ['Accept' => 'application/json'])->assertStatus(500);

        $this->assertNothingChanged($before);
        $this->assertSame(0, Applicant::count());
    }

    public function test_a_failed_resume_replacement_keeps_the_old_file_and_removes_the_new_one(): void
    {
        $a = $this->applicantWithFiles();
        Sanctum::actingAs($this->hr);
        $before = $this->snapshot();
        Applicant::updating($this->boom());

        $this->post("/api/applicants/{$a->id}", ['_method' => 'PUT', 'name' => 'Seed', 'position_applied' => 'Driver', 'resume' => $this->pdf('new.pdf')], ['Accept' => 'application/json'])->assertStatus(500);

        $this->assertNothingChanged($before, 'The old resume must stay until the update succeeded.');
        $this->assertSame('resumes/seed.pdf', DB::table('applicants')->where('id', $a->id)->value('resume_path'));
    }

    public function test_a_successful_resume_replacement_removes_the_old_file_and_keeps_the_new_one(): void
    {
        $a = $this->applicantWithFiles();
        Sanctum::actingAs($this->hr);

        $this->post("/api/applicants/{$a->id}", ['_method' => 'PUT', 'name' => 'Seed', 'position_applied' => 'Driver', 'resume' => $this->pdf('new.pdf')], ['Accept' => 'application/json'])->assertOk();

        $new = $a->fresh()->resume_path;
        $this->assertNotSame('resumes/seed.pdf', $new);
        Storage::disk('local')->assertExists($new);
        Storage::disk('local')->assertMissing('resumes/seed.pdf');
        Storage::disk('local')->assertExists('applicant-documents/seed-photo.png'); // the documents are not touched
    }

    public function test_removing_a_resume_deletes_the_file_only_after_the_update_and_keeps_it_when_the_update_fails(): void
    {
        $a = $this->applicantWithFiles();
        Sanctum::actingAs($this->hr);
        $before = $this->snapshot();
        Applicant::updating($this->boom());
        $this->post("/api/applicants/{$a->id}", ['_method' => 'PUT', 'name' => 'Seed', 'position_applied' => 'Driver', 'remove_resume' => true], ['Accept' => 'application/json'])->assertStatus(500);
        $this->assertNothingChanged($before);

        Applicant::flushEventListeners();
        $this->post("/api/applicants/{$a->id}", ['_method' => 'PUT', 'name' => 'Seed', 'position_applied' => 'Driver', 'remove_resume' => true], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('local')->assertMissing('resumes/seed.pdf');
        $this->assertNull($a->fresh()->resume_path);
    }

    // ---------------------------------------------------------------- 3. applicant deletion

    public function test_deleting_an_applicant_removes_the_resume_and_every_document_file_and_nothing_else(): void
    {
        $a = $this->applicantWithFiles();
        $other = $this->applicantWithFiles(); // a second applicant must keep its files
        $other->update(['phone' => '+639170000101']);
        Storage::disk('local')->put('applicant-documents/historical-orphan.png', 'old orphan'); // an orphan from before: not ours to delete
        foreach ($other->documents ?? [] as $d) {
            $d->delete();
        }
        Sanctum::actingAs($this->hr);

        $this->deleteJson("/api/applicants/{$a->id}")->assertOk();

        Storage::disk('local')->assertMissing('resumes/seed.pdf');
        Storage::disk('local')->assertMissing('applicant-documents/seed-photo.png');
        Storage::disk('local')->assertMissing('applicant-documents/seed-id.png');
        Storage::disk('local')->assertExists('applicant-documents/historical-orphan.png');
        $this->assertSame(0, ApplicantDocument::where('applicant_id', $a->id)->count());
    }

    public function test_a_failed_applicant_deletion_keeps_every_file(): void
    {
        $a = $this->applicantWithFiles();
        Sanctum::actingAs($this->hr);
        $before = $this->snapshot();
        Applicant::deleting($this->boom());

        $this->deleteJson("/api/applicants/{$a->id}")->assertStatus(500);

        $this->assertNothingChanged($before, 'Files go only after the record is really gone.');
        $this->assertSame(2, ApplicantDocument::where('applicant_id', $a->id)->count());
    }

    // ---------------------------------------------------------------- 4. the other create paths

    public function test_a_failed_leave_record_leaves_no_attachment(): void
    {
        Sanctum::actingAs($this->empUser);
        $before = $this->snapshot();
        LeaveRequest::creating($this->boom());

        $this->post('/api/my-leave-requests', ['leave_type' => 'vacation', 'start_date' => '2026-12-14', 'reason' => 'x', 'attachment' => $this->pdf('cert.pdf')], ['Accept' => 'application/json'])->assertStatus(500);

        $this->assertNothingChanged($before);
        $this->assertSame(0, LeaveRequest::count());
    }

    public function test_a_successful_leave_keeps_its_attachment(): void
    {
        Sanctum::actingAs($this->empUser);
        $this->post('/api/my-leave-requests', ['leave_type' => 'vacation', 'start_date' => '2026-12-14', 'reason' => 'x', 'attachment' => $this->pdf('cert.pdf')], ['Accept' => 'application/json'])->assertCreated();
        Storage::disk('local')->assertExists(LeaveRequest::first()->attachment_path);
    }

    private function receipt(): Receipt
    {
        $d = Delivery::create(['delivery_no' => 'DEL-1', 'driver_id' => $this->hr->id, 'customer_id' => $this->cust->id, 'status' => 'delivered', 'scheduled_date' => now()->toDateString(), 'prepared_by' => $this->hr->id]);
        $p = DeliveryProof::create(['delivery_id' => $d->id, 'salesman_id' => $this->hr->id, 'quantity' => 1, 'photo_path' => 'x.jpg', 'confirmed_at' => now()]);

        return Receipt::create(['receipt_no' => 'R-1', 'delivery_id' => $d->id, 'delivery_proof_id' => $p->id, 'customer_id' => $this->cust->id, 'salesman_id' => $this->hr->id, 'quantity' => 1, 'unit_price' => 50, 'total_amount' => 50, 'payment_status' => 'unpaid']);
    }

    public function test_a_failed_payment_proof_record_leaves_no_file(): void
    {
        $r = $this->receipt();
        Sanctum::actingAs($this->custUser);
        $before = $this->snapshot();
        PaymentProof::creating($this->boom());

        $this->post("/api/my-receipts/{$r->id}/payment-proofs", ['file' => $this->pdf('p.pdf'), 'method' => 'gcash'], ['Accept' => 'application/json'])->assertStatus(500);

        $this->assertNothingChanged($before);
    }

    public function test_a_failed_employee_document_record_leaves_no_file(): void
    {
        Sanctum::actingAs($this->hr);
        $before = $this->snapshot();
        EmployeeDocument::creating($this->boom());

        $this->post("/api/employees/{$this->emp->id}/documents", ['type' => 'photo', 'file' => $this->png('p.png')], ['Accept' => 'application/json'])->assertStatus(500);

        $this->assertNothingChanged($before);
    }

    // memo: several files in one request
    private function memo(): EmployeeMemo
    {
        Sanctum::actingAs($this->hr);
        $id = $this->postJson('/api/memos', ['employee_id' => $this->emp->id, 'violation' => array_key_first(EmployeeMemo::VIOLATIONS), 'incident_date' => '2026-10-10', 'subject' => 'Memo', 'body' => 'An inert memo body that is long enough for the rule.'])->assertCreated()->json('id');

        return EmployeeMemo::findOrFail($id);
    }

    public function test_a_memo_created_with_files_leaves_nothing_when_the_second_file_record_fails(): void
    {
        Sanctum::actingAs($this->hr);
        $before = $this->snapshot();
        $n = 0;
        EmployeeMemoFile::creating(function () use (&$n) {
            if (++$n === 2) {
                throw new RuntimeException('injected failure: secret-detail');
            }
        });

        $this->post('/api/memos', ['employee_id' => $this->emp->id, 'violation' => array_key_first(EmployeeMemo::VIOLATIONS), 'incident_date' => '2026-10-10', 'subject' => 'Memo', 'body' => 'An inert memo body that is long enough for the rule.', 'files' => [$this->pdf('a.pdf'), $this->pdf('b.pdf'), $this->pdf('c.pdf')]], ['Accept' => 'application/json'])->assertStatus(500);

        $this->assertNothingChanged($before);
        $this->assertSame(0, EmployeeMemo::count());
        $this->assertSame(0, EmployeeMemoFile::count());
    }

    public function test_adding_files_to_a_memo_is_all_or_nothing_and_never_touches_existing_files(): void
    {
        $memo = $this->memo();
        $this->post("/api/memos/{$memo->id}/files", ['kind' => 'attachment', 'files' => [$this->pdf('first.pdf')]], ['Accept' => 'application/json'])->assertOk();
        $before = $this->snapshot();
        $rows = EmployeeMemoFile::count();
        $n = 0;
        EmployeeMemoFile::creating(function () use (&$n) {
            if (++$n === 2) {
                throw new RuntimeException('injected failure: secret-detail');
            }
        });

        $this->post("/api/memos/{$memo->id}/files", ['kind' => 'attachment', 'files' => [$this->pdf('a.pdf'), $this->pdf('b.pdf')]], ['Accept' => 'application/json'])->assertStatus(500);

        $this->assertNothingChanged($before);
        $this->assertSame($rows, EmployeeMemoFile::count());
    }

    public function test_a_signed_copy_that_fails_to_archive_the_memo_leaves_no_file_and_the_status_alone(): void
    {
        $memo = $this->memo();
        $memo->update(['status' => 'sent', 'issued_at' => now()]);
        Sanctum::actingAs($this->hr);
        $before = $this->snapshot();
        EmployeeMemo::updating($this->boom());

        $this->post("/api/memos/{$memo->id}/files", ['kind' => 'signed_copy', 'files' => [$this->pdf('signed.pdf')]], ['Accept' => 'application/json'])->assertStatus(500);

        $this->assertNothingChanged($before);
        $this->assertSame('sent', DB::table('employee_memos')->where('id', $memo->id)->value('status'));
        $this->assertSame(0, EmployeeMemoFile::count());
    }

    // ---------------------------------------------------------------- face photo

    private function enrollPayload(int $employeeId): array
    {
        return ['worker_name' => 'Guard', 'role' => 'guard', 'employee_id' => $employeeId, 'descriptor' => array_fill(0, 128, 0.1), 'photo' => 'data:image/jpeg;base64,'.self::JPG];
    }

    private function guard(string $code = 'EMP-0200'): Employee
    {
        return Employee::create(['employee_id' => $code, 'first_name' => 'G', 'last_name' => $code, 'position' => 'Guard', 'date_hired' => '2026-01-01', 'shift' => 'day', 'employment_type' => 'regular', 'is_active' => true]);
    }

    public function test_a_failed_face_record_leaves_no_photo(): void
    {
        Sanctum::actingAs($this->hr);
        $g = $this->guard();
        $before = $this->snapshot();
        FaceData::creating($this->boom());

        $this->postJson('/api/face-enroll', $this->enrollPayload($g->id))->assertStatus(500);

        $this->assertNothingChanged($before);
    }

    public function test_a_failed_face_photo_replacement_keeps_the_old_photo_and_removes_the_new_one(): void
    {
        Sanctum::actingAs($this->hr);
        $g = $this->guard();
        $id = $this->postJson('/api/face-enroll', $this->enrollPayload($g->id))->assertCreated()->json('face_data.id');
        $old = FaceData::find($id)->photo_path;
        $before = $this->snapshot();
        FaceData::updating($this->boom());

        $this->putJson("/api/face-data/{$id}", ['descriptor' => array_fill(0, 128, 0.2), 'photo' => 'data:image/jpeg;base64,'.self::JPG])->assertStatus(500);

        $this->assertNothingChanged($before, 'The old photo must stay until the update succeeded.');
        $this->assertSame($old, DB::table('face_data')->where('id', $id)->value('photo_path'));
    }

    public function test_a_successful_face_photo_replacement_removes_the_old_photo(): void
    {
        Sanctum::actingAs($this->hr);
        $g = $this->guard();
        $id = $this->postJson('/api/face-enroll', $this->enrollPayload($g->id))->assertCreated()->json('face_data.id');
        $old = FaceData::find($id)->photo_path;

        $this->putJson("/api/face-data/{$id}", ['descriptor' => array_fill(0, 128, 0.2), 'photo' => 'data:image/jpeg;base64,'.self::JPG])->assertOk();

        $new = FaceData::find($id)->photo_path;
        $this->assertNotSame($old, $new);
        Storage::disk('local')->assertExists($new);
        Storage::disk('local')->assertMissing($old);
    }

    // ---------------------------------------------------------------- headers, names, 404

    public function test_every_private_download_sends_the_same_protective_headers(): void
    {
        $memo = $this->memo();
        $this->post("/api/memos/{$memo->id}/files", ['kind' => 'attachment', 'files' => [$this->pdf('ev.pdf')]], ['Accept' => 'application/json'])->assertOk();
        $file = EmployeeMemoFile::first();
        $docId = $this->post("/api/employees/{$this->emp->id}/documents", ['type' => 'photo', 'file' => $this->png('p.png')], ['Accept' => 'application/json'])->assertCreated()->json('document.id');

        foreach (["/api/memos/{$memo->id}/files/{$file->id}", "/api/employees/{$this->emp->id}/documents/{$docId}"] as $url) {
            $res = $this->get($url, ['Accept' => 'application/json'])->assertOk();
            $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'), $url);
            $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'), $url);
            $this->assertStringContainsString('private', (string) $res->headers->get('Cache-Control'), $url);
        }
    }

    public function test_memo_file_names_are_cleaned_like_every_other_upload(): void
    {
        $memo = $this->memo();
        $this->post("/api/memos/{$memo->id}/files", ['kind' => 'attachment', 'files' => [$this->pdf("a\r\n<b>.pdf")]], ['Accept' => 'application/json'])->assertOk();

        $name = EmployeeMemoFile::first()->original_name;
        $this->assertDoesNotMatchRegularExpression('/[\r\n<>]/', $name);
    }

    public function test_a_missing_record_gives_a_generic_404_without_internal_names(): void
    {
        Sanctum::actingAs($this->hr);
        foreach (['/api/applicants/999/resume', '/api/memos/999/files/1', '/api/employees/999/documents/1', '/api/face-data/999/photo'] as $url) {
            $res = $this->getJson($url)->assertNotFound();
            $this->assertStringNotContainsString('App\\Models', $res->getContent(), $url);
            $this->assertStringNotContainsString('No query results', $res->getContent(), $url);
        }
    }

    // ================================================================ rollback, write failures, 404

    // ---- existing files are deleted only after the database commit (outer transactions too)

    public function test_applicant_files_are_deleted_only_after_the_commit_and_survive_a_rollback(): void
    {
        $a = $this->applicantWithFiles();
        Sanctum::actingAs($this->hr);

        DB::beginTransaction(); // an outer transaction around the whole request
        $this->deleteJson("/api/applicants/{$a->id}")->assertOk();
        Storage::disk('local')->assertExists('resumes/seed.pdf');                       // not deleted yet
        Storage::disk('local')->assertExists('applicant-documents/seed-photo.png');
        DB::rollBack();

        $this->assertNotNull(Applicant::find($a->id), 'The rollback brought the applicant back.');
        Storage::disk('local')->assertExists('resumes/seed.pdf');
        Storage::disk('local')->assertExists('applicant-documents/seed-photo.png');
        Storage::disk('local')->assertExists('applicant-documents/seed-id.png');
    }

    public function test_applicant_files_go_once_the_outer_transaction_commits(): void
    {
        $a = $this->applicantWithFiles();
        Sanctum::actingAs($this->hr);

        DB::beginTransaction();
        $this->deleteJson("/api/applicants/{$a->id}")->assertOk();
        Storage::disk('local')->assertExists('resumes/seed.pdf');
        DB::commit();

        Storage::disk('local')->assertMissing('resumes/seed.pdf');
        Storage::disk('local')->assertMissing('applicant-documents/seed-photo.png');
        Storage::disk('local')->assertMissing('applicant-documents/seed-id.png');
    }

    public function test_a_resume_replacement_inside_a_rolled_back_transaction_keeps_the_old_file(): void
    {
        $a = $this->applicantWithFiles();
        Sanctum::actingAs($this->hr);

        DB::beginTransaction();
        $this->post("/api/applicants/{$a->id}", ['_method' => 'PUT', 'name' => 'Seed', 'position_applied' => 'Driver', 'resume' => $this->pdf('new.pdf')], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('local')->assertExists('resumes/seed.pdf'); // the old file waits for the commit
        DB::rollBack();

        Storage::disk('local')->assertExists('resumes/seed.pdf');
        $this->assertSame('resumes/seed.pdf', DB::table('applicants')->where('id', $a->id)->value('resume_path'));
    }

    public function test_a_face_photo_replacement_and_deletion_inside_a_rolled_back_transaction_keep_the_photo(): void
    {
        Sanctum::actingAs($this->hr);
        $g = $this->guard();
        $id = $this->postJson('/api/face-enroll', $this->enrollPayload($g->id))->assertCreated()->json('face_data.id');
        $old = FaceData::find($id)->photo_path;

        DB::beginTransaction();
        $this->putJson("/api/face-data/{$id}", ['descriptor' => array_fill(0, 128, 0.2), 'photo' => 'data:image/jpeg;base64,'.self::JPG])->assertOk();
        Storage::disk('local')->assertExists($old);
        DB::rollBack();
        Storage::disk('local')->assertExists($old);

        DB::beginTransaction();
        $this->deleteJson("/api/face-data/{$id}")->assertOk();
        Storage::disk('local')->assertExists($old);
        DB::rollBack();
        Storage::disk('local')->assertExists($old);
        $this->assertNotNull(FaceData::find($id));
    }

    public function test_cancelling_a_leave_and_removing_an_employee_document_keep_their_files_on_rollback_and_remove_them_on_commit(): void
    {
        Sanctum::actingAs($this->empUser);
        $this->post('/api/my-leave-requests', ['leave_type' => 'vacation', 'start_date' => '2026-12-14', 'reason' => 'x', 'attachment' => $this->pdf('cert.pdf')], ['Accept' => 'application/json'])->assertCreated();
        $leave = LeaveRequest::first();
        Sanctum::actingAs($this->hr);
        $docId = $this->post("/api/employees/{$this->emp->id}/documents", ['type' => 'photo', 'file' => $this->png('p.png')], ['Accept' => 'application/json'])->assertCreated()->json('document.id');
        $docPath = EmployeeDocument::find($docId)->path;

        DB::beginTransaction();
        Sanctum::actingAs($this->empUser);
        $this->deleteJson("/api/my-leave-requests/{$leave->id}")->assertOk();
        Sanctum::actingAs($this->hr);
        $this->deleteJson("/api/employees/{$this->emp->id}/documents/{$docId}")->assertOk();
        Storage::disk('local')->assertExists($leave->attachment_path);
        Storage::disk('local')->assertExists($docPath);
        DB::rollBack();
        Storage::disk('local')->assertExists($leave->attachment_path);
        Storage::disk('local')->assertExists($docPath);

        Sanctum::actingAs($this->empUser);
        $this->deleteJson("/api/my-leave-requests/{$leave->id}")->assertOk();
        Sanctum::actingAs($this->hr);
        $this->deleteJson("/api/employees/{$this->emp->id}/documents/{$docId}")->assertOk();
        Storage::disk('local')->assertMissing($leave->attachment_path);
        Storage::disk('local')->assertMissing($docPath);
    }

    public function test_the_retention_job_keeps_files_when_its_transaction_fails_and_removes_them_when_it_succeeds(): void
    {
        $employee = $this->emp;
        Storage::disk('local')->put('employee-documents/d.png', 'doc');
        EmployeeDocument::create(['employee_id' => $employee->id, 'type' => 'photo', 'path' => 'employee-documents/d.png', 'original_name' => 'd.png', 'mime' => 'image/png', 'size' => 3]);
        Storage::disk('local')->put('leave-attachments/l.pdf', 'leave');
        LeaveRequest::create(['employee_id' => $employee->id, 'leave_type' => 'vacation', 'start_date' => '2026-12-14', 'end_date' => '2026-12-14', 'total_days' => 1, 'reason' => 'x', 'status' => 'pending', 'attachment_path' => 'leave-attachments/l.pdf', 'attachment_disk' => 'local']);

        Employee::saving($this->boom());
        try {
            \App\Support\ArchiveExpiry::anonymize($employee->fresh());
            $this->fail('The injected failure should have stopped the job.');
        } catch (RuntimeException) {
        }
        Storage::disk('local')->assertExists('employee-documents/d.png');
        Storage::disk('local')->assertExists('leave-attachments/l.pdf');

        Employee::flushEventListeners();
        \App\Support\ArchiveExpiry::anonymize($employee->fresh());
        Storage::disk('local')->assertMissing('employee-documents/d.png');
        Storage::disk('local')->assertMissing('leave-attachments/l.pdf');
    }

    // ---- StoredFiles when the file write or the cleanup itself fails

    private function failingDisk(array $methods): void
    {
        $mock = \Mockery::mock(Storage::disk('local'))->makePartial();
        foreach ($methods as $method => $result) {
            $expectation = $mock->shouldReceive($method);
            $result instanceof \Throwable ? $expectation->andThrow($result) : $expectation->andReturn($result);
        }
        Storage::set('local', $mock);
    }

    public function test_a_file_that_cannot_be_written_is_never_reported_as_success(): void
    {
        $this->failingDisk(['putFileAs' => false, 'put' => false]);
        Sanctum::actingAs($this->hr);

        $this->post('/api/apply', $this->form(7) + ['resume' => $this->pdf('cv.pdf')], ['Accept' => 'application/json'])->assertStatus(500);
        $this->assertSame(0, Applicant::count());

        $this->post("/api/employees/{$this->emp->id}/documents", ['type' => 'photo', 'file' => $this->png('p.png')], ['Accept' => 'application/json'])->assertStatus(500);
        $this->assertSame(0, EmployeeDocument::count());

        $g = $this->guard();
        $this->postJson('/api/face-enroll', $this->enrollPayload($g->id))->assertStatus(500);
        $this->assertSame(0, FaceData::count());
    }

    public function test_a_failing_cleanup_never_hides_the_original_error(): void
    {
        $this->withoutExceptionHandling();
        Sanctum::actingAs($this->hr);
        $this->failingDisk(['delete' => new RuntimeException('cleanup boom')]);
        Applicant::creating($this->boom());

        try {
            $this->post('/api/applicants', ['name' => 'New One', 'position_applied' => 'Driver', 'phone' => '09171230002', 'resume' => $this->pdf('cv.pdf')], ['Accept' => 'application/json']);
            $this->fail('The injected failure should have reached the caller.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('injected failure', $e->getMessage(), 'The original error must come out, not the cleanup error.');
        }
    }

    public function test_a_cleanup_that_fails_is_logged_and_does_not_throw(): void
    {
        $this->failingDisk(['delete' => new RuntimeException('cleanup boom')]);
        \Illuminate\Support\Facades\Log::spy();
        $stored = new \App\Support\StoredFiles('local');
        $stored->add('resumes/x.pdf');

        $stored->discard();

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    public function test_an_old_file_that_cannot_be_deleted_after_a_committed_replacement_does_not_fail_the_request(): void
    {
        $a = $this->applicantWithFiles();
        Sanctum::actingAs($this->hr);
        \Illuminate\Support\Facades\Log::spy();
        $this->failingDisk(['delete' => false]);

        $this->post("/api/applicants/{$a->id}", ['_method' => 'PUT', 'name' => 'Seed', 'position_applied' => 'Driver', 'resume' => $this->pdf('new.pdf')], ['Accept' => 'application/json'])->assertOk();

        $this->assertNotSame('resumes/seed.pdf', $a->fresh()->resume_path, 'The record already points to the new file.');
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    // ---- the 404 handler keeps intentional messages and formats

    public function test_an_intentional_404_message_is_kept_and_only_the_model_lookup_text_is_replaced(): void
    {
        $lonely = User::create(['name' => 'No Store', 'email' => 'nostore@atice.test', 'password' => 'secret-password', 'role' => 'customer', 'is_active' => true]);
        Sanctum::actingAs($lonely);
        $this->postJson('/api/my-receipts/1/payment-proofs')->assertNotFound()->assertJsonPath('message', 'No customer account linked to this login.');

        Sanctum::actingAs($this->hr);
        $this->getJson('/api/applicants/999/resume')->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        // a handled "no resume" answer is untouched
        $a = Applicant::create(['name' => 'No Resume', 'phone' => '+639170000300', 'position_applied' => 'Driver', 'status' => 'pending', 'source' => 'qr']);
        $this->getJson("/api/applicants/{$a->id}/resume")->assertNotFound()->assertJsonPath('message', 'This applicant has no resume attached.');
    }
}
