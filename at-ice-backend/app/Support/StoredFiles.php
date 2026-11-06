<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

// File writes and deletes of one request. A database transaction does not undo file operations, so:
//
//  1. Writes: every file this request writes is recorded; if the request fails, only those files
//     are removed (a file that existed before is never in the list):
//
//         $stored = new StoredFiles('local');
//         try {
//             $path = $stored->store($file, 'resumes');   // throws if the file was NOT written
//             ... save the record(s) ...
//         } catch (\Throwable $e) {
//             $stored->discard();   // never throws, never hides $e
//             throw $e;
//         }
//
//  2. Deletes of files that already exist (replace, remove, delete): do them with
//     deleteAfterCommit() AFTER the record change. The file goes only once the outermost database
//     transaction has really committed; a rollback (or an outer transaction that is rolled back
//     later) keeps the file. Outside a transaction it runs at once.
class StoredFiles
{
    /** @var array<int, string> */
    private array $paths = [];

    public function __construct(private string $disk = 'local') {}

    // Writes an uploaded file and records it. A write that did not happen is an error, never a
    // success with a missing file.
    public function store(UploadedFile $file, string $directory, ?string $name = null): string
    {
        $path = $name === null
            ? $file->store($directory, $this->disk)
            : $file->storeAs($directory, $name, $this->disk);
        if (! $path) {
            throw new RuntimeException("The file could not be stored on disk '{$this->disk}'.");
        }

        return $this->add($path);
    }

    // Records a path that was written some other way (for example Storage::put).
    public function add(string $path): string
    {
        $this->paths[] = $path;

        return $path;
    }

    /** @return array<int, string> */
    public function paths(): array
    {
        return $this->paths;
    }

    // Removes the files this request wrote. A cleanup that fails is logged and ignored: the
    // original failure is the one the caller must see.
    public function discard(): void
    {
        foreach ($this->paths as $path) {
            self::deleteNow($this->disk, $path);
        }
        $this->paths = [];
    }

    // Deletes existing files after the outermost transaction commits (see the note above).
    public static function deleteAfterCommit(string $disk, array|string|null $paths): void
    {
        $paths = array_values(array_filter((array) $paths));
        if (! $paths) {
            return;
        }
        DB::afterCommit(function () use ($disk, $paths) {
            foreach ($paths as $path) {
                self::deleteNow($disk, $path);
            }
        });
    }

    private static function deleteNow(string $disk, string $path): void
    {
        try {
            if (Storage::disk($disk)->delete($path) === false) {
                Log::warning('A stored file could not be deleted', ['disk' => $disk, 'path' => $path]);
            }
        } catch (Throwable $e) {
            Log::warning('A stored file could not be deleted', ['disk' => $disk, 'path' => $path, 'error' => $e->getMessage()]);
        }
    }
}
