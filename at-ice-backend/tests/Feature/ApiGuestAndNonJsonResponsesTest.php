<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Guests on the API get a JSON 401 (never a login redirect or a server error), and
// files, CSV and pictures keep their own content type even when the client asks for HTML.
class ApiGuestAndNonJsonResponsesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function hr(): User
    {
        $hr = User::create(['name' => 'Hr', 'email' => 'hr@atice.cloud', 'password' => 'secret-password', 'role' => 'hr']);
        Sanctum::actingAs($hr);

        return $hr;
    }

    private function guard(): Employee
    {
        return Employee::create([
            'employee_id' => 'EMP-0001', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'position' => 'Guard',
            'date_hired' => now()->subDay()->toDateString(), 'shift' => 'day', 'employment_type' => 'regular',
        ]);
    }

    private function jpegDataUrl(): string
    {
        return 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';
    }

    public function test_guest_api_request_without_an_accept_header_gets_a_json_401(): void
    {
        $this->get('/api/me')->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_guest_api_request_that_asks_for_html_still_gets_a_json_401(): void
    {
        $this->withHeaders(['Accept' => 'text/html'])
            ->get('/api/me')
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_guest_broadcasting_auth_gets_a_json_401_not_a_server_error(): void
    {
        $this->post('/api/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'private-x'])
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_validation_error_is_json_for_a_client_that_asks_for_html(): void
    {
        $this->withHeaders(['Accept' => 'text/html'])
            ->post('/api/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_attendance_csv_export_keeps_its_content_type_for_a_client_that_asks_for_html(): void
    {
        $this->hr();
        $emp = $this->guard();

        $this->withHeaders(['Accept' => 'text/html'])
            ->get("/api/attendances/export?from=2026-10-12&to=2026-10-25&employee_id={$emp->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_employee_document_download_keeps_its_mime_type_for_a_client_that_asks_for_html(): void
    {
        $hr = $this->hr();
        $emp = $this->guard();
        Storage::disk('local')->put('employee-documents/nbi.pdf', '%PDF-1.4 test');
        $doc = EmployeeDocument::create([
            'employee_id' => $emp->id, 'type' => 'nbi', 'path' => 'employee-documents/nbi.pdf',
            'original_name' => 'nbi.pdf', 'mime' => 'application/pdf', 'size' => 13, 'uploaded_by' => $hr->id,
        ]);

        $this->withHeaders(['Accept' => 'text/html'])
            ->get("/api/employees/{$emp->id}/documents/{$doc->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_face_photo_keeps_its_jpeg_type_for_an_image_client(): void
    {
        $this->hr();
        $emp = $this->guard();

        $id = $this->postJson('/api/face-enroll', [
            'worker_name' => 'Dela Cruz, Juan', 'role' => 'guard', 'employee_id' => $emp->id,
            'descriptor' => array_fill(0, 128, 0.1), 'photo' => $this->jpegDataUrl(),
        ])->assertCreated()->json('face_data.id');

        $this->withHeaders(['Accept' => 'image/*'])
            ->get("/api/face-data/{$id}/photo")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }
}
