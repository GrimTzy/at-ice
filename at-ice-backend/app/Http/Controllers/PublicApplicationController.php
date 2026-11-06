<?php

namespace App\Http\Controllers;

use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\Employee;
use App\Services\AddressFormatter;
use App\Services\DriverLicense;
use App\Services\PhoneFormatter;
use App\Support\Biodata;
use App\Support\StoredFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// The public job application form (/apply), opened by scanning the QR code HR
// prints from HRIS > Interviews. No login: anyone with the link can apply.
// It only creates a pending applicant (source "qr"), with the resume or CV
// they attach, which HR sees in the
// Interviews tab with a live notice. Spam guards: a per-IP limit on the route,
// a hidden "website" field that people leave empty, and one open application
// per mobile number.
// Digital biodata (owner, 2026-10-20): the applicant first agrees to the Data
// Privacy Act consent (recorded with the time and IP), then gives personal
// details, contact and emergency contact, education and work history, the
// driver's license for Driver applicants, and a 2x2 photo and documents.
// Government numbers are never asked here (they are added by HR once hired).
// Everything lands as a Pending application in HRIS > Interviews and carries
// over to Add Employee when HR hires the applicant.
class PublicApplicationController extends Controller
{
    // All files of one application together; the proxy body limit is 12 MB (nginx client_max_body_size).
    private const MAX_TOTAL_BYTES = 11 * 1024 * 1024;

    public function store(Request $request)
    {
        // Bots fill every field, including the hidden one. Look successful so
        // they do not retry, but save nothing.
        if (filled($request->input('website'))) {
            return response()->json(['message' => 'Application sent.'], 201);
        }

        // Office and admin roles are never open to the public. A request that
        // asks for one anyway (sent by hand, not from the form) is refused
        // outright and nothing is saved.
        $position = (string) $request->input('position_applied', '');
        if ($position !== '' && (stripos($position, 'admin') !== false || ! in_array($position, Applicant::PUBLIC_POSITIONS, true))) {
            Log::warning('Public application with a position that is not open to the public', ['position' => mb_substr($position, 0, 60), 'ip' => $request->ip()]);

            return response()->json([
                'message' => 'This position is not open for applications.',
                'errors' => ['position_applied' => ['Choose a position from the list.']],
            ], stripos($position, 'admin') !== false ? 403 : 422);
        }

        if ($request->filled('phone')) {
            $request->merge(['phone' => PhoneFormatter::mobile((string) $request->phone)]);
        }
        $request->merge(['email' => Applicant::normalizeEmail($request->input('email'))]);
        Biodata::clean($request);
        if ($request->has('license_number')) {
            $request->merge(['license_number' => DriverLicense::normalize($request->license_number)]);
        }
        $driver = $request->input('position_applied') === 'Driver';

        $data = $request->validate([
            'name' => 'required|string|min:3|max:255',
            'phone' => ['required', 'regex:'.PhoneFormatter::MOBILE_REGEX],
            'email' => ['nullable', 'email:rfc,filter', 'max:255', Rule::unique('applicants', 'email')],
            // Employee ID from a referral link (/job-applications/EMP-0001)
            'referral' => 'nullable|string|max:20',
            'position_applied' => ['required', Rule::in(Applicant::PUBLIC_POSITIONS)],
            'notes' => 'nullable|string|max:1000',
            // Resume or CV: PDF or Word, up to 5 MB
            'resume' => Applicant::RESUME_RULE,
            // Data Privacy Act of 2012: nothing is kept without this consent
            'consent' => 'accepted',
            'photo' => 'nullable|file|max:10240|mimes:jpg,jpeg,png,webp',
            'documents' => 'nullable|array|max:4',
            'documents.*' => 'file|max:10240|mimes:pdf,jpg,jpeg,png,webp',
            'license_number' => [$driver ? 'required' : 'nullable', 'regex:'.DriverLicense::REGEX],
            'license_expiry' => $driver ? DriverLicense::expiryRules(true) : ['nullable', 'date'],
        ] + Biodata::rules() + AddressFormatter::OPTIONAL_RULES, Biodata::messages() + DriverLicense::EXPIRY_MESSAGES + [
            'consent.accepted' => 'Agree to the data privacy consent to send your application.',
            'photo.mimes' => 'The 2x2 photo must be a picture (JPG, PNG or WebP).',
            'photo.max' => 'The photo is too large. Use one up to 10 MB.',
            'documents.max' => 'Attach up to 4 documents.',
            'documents.*.mimes' => 'Attach documents as a PDF or a picture (JPG, PNG or WebP).',
            'documents.*.max' => 'Each document must be up to 10 MB.',
            'license_number.required' => "Driver applicants must give their driver's license number.",
            'license_number.regex' => DriverLicense::MESSAGE,
            'name.required' => 'Enter your full name.',
            'phone.required' => 'Enter your mobile number so HR can contact you.',
            'phone.regex' => PhoneFormatter::MOBILE_MESSAGE,
            'position_applied.required' => 'Choose the position you are applying for.',
            'position_applied.in' => 'Choose a position from the list.',
            'email.unique' => 'You already applied with this email. HR will contact you.',
            'postal_code.regex' => 'Postal code must be 4 digits.',
            'resume.mimes' => 'Attach your resume as a PDF or a Word file (DOC, DOCX).',
        ] + Applicant::RESUME_MESSAGES);

        // One open application per mobile number.
        $open = Applicant::where('phone', $data['phone'])->whereIn('status', ['pending', 'interviewed'])->exists();
        if ($open) {
            throw ValidationException::withMessages([
                'phone' => ['You already have an application with this mobile number. HR will contact you.'],
            ]);
        }

        // Everything sent together must fit the request-body limit of the proxy (nginx
        // client_max_body_size 12m) with room for the form fields. A clear message here, and
        // before anything is stored; the browser also checks this before sending (ApplyPage).
        $total = collect([$request->file('resume'), $request->file('photo'), ...$request->file('documents', [])])->filter()->sum(fn ($f) => $f->getSize());
        if ($total > self::MAX_TOTAL_BYTES) {
            throw ValidationException::withMessages([
                'documents' => ['The files are too big together ('.round($total / 1048576, 1).' MB). All files together must be 11 MB or less: remove a file or use a smaller one.'],
            ]);
        }

        // Files go to the private disk (only HR opens them: ApplicantController@resume). The
        // application and its files are all-or-nothing: if any step fails the records are rolled
        // back AND the files this request wrote are removed (a transaction does not undo files).
        $stored = new StoredFiles('local');
        try {
            DB::transaction(function () use ($request, $data, $driver, $stored) {
                $resume = $request->file('resume');
                $resumePath = $resume ? $stored->store($resume, 'resumes') : null;
                $resumeName = $resume ? mb_substr(preg_replace('/[^\pL\pN ._()-]/u', '_', $resume->getClientOriginalName()), 0, 255) : null;

                // Referral: recorded only (no automatic bonus; Admin adds one in payroll).
                $referrer = filled($data['referral'] ?? null)
                    ? Employee::where('employee_id', strtoupper(trim($data['referral'])))->where('is_active', true)->value('id')
                    : null;

                $applicant = Applicant::create([
                    'name' => trim($data['name']),
                    'phone' => $data['phone'],
                    'email' => $data['email'] ?? null,
                    'position_applied' => $data['position_applied'],
                    // What the applicant wrote goes in the notes, marked as theirs.
                    'notes' => filled($data['notes'] ?? null) ? 'From the applicant: '.trim($data['notes']) : null,
                    'status' => 'pending',
                    'source' => 'qr',
                    'referred_by_employee_id' => $referrer,
                    'resume_path' => $resumePath ?: null,
                    'resume_name' => $resumePath ? $resumeName : null,
                    'consented_at' => now(),
                    'consent_ip' => $request->ip(),
                    'license_number' => $driver ? ($data['license_number'] ?? null) : null,
                    'license_expiry' => $driver ? ($data['license_expiry'] ?? null) : null,
                ] + array_intersect_key($data, AddressFormatter::OPTIONAL_RULES) + array_intersect_key($data, array_flip(Biodata::FIELDS)));
                if (! $driver) {
                    $applicant->update(['license_restrictions' => null]);
                }

                // 2x2 photo and other documents, private like the resume
                $files = array_merge(
                    $request->file('photo') ? [['photo', $request->file('photo')]] : [],
                    array_map(fn ($f) => ['other', $f], $request->file('documents', [])),
                );
                foreach ($files as [$type, $file]) {
                    ApplicantDocument::create([
                        'applicant_id' => $applicant->id,
                        'type' => $type,
                        'path' => $stored->store($file, 'applicant-documents', Str::uuid().'.'.$file->extension()),
                        'original_name' => mb_substr(preg_replace('/[^\pL\pN ._()-]/u', '_', $file->getClientOriginalName()), 0, 255),
                        'mime' => $file->getMimeType() ?: 'application/octet-stream',
                        'size' => $file->getSize(),
                    ]);
                }
            });
        } catch (\Throwable $e) {
            $stored->discard();
            throw $e;
        }

        return response()->json(['message' => 'Application sent.'], 201);
    }
}
