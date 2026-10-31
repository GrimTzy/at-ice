<?php

namespace App\Support\ThesisDemo;

use Illuminate\Support\Facades\DB;

// The list of rows a dataset added (table demo_dataset_records). Every row the
// installer makes is written here in the same transaction, under a scenario
// key that is unique in the dataset, so a rerun finds it and skips it.
class Manifest
{
    public const TABLE = 'demo_dataset_records';

    public function __construct(public readonly string $dataset) {}

    // The record id stored under a key, or null.
    public function id(string $key): ?int
    {
        $id = DB::table(self::TABLE)->where('dataset', $this->dataset)->where('scenario_key', $key)->value('record_id');

        return $id === null ? null : (int) $id;
    }

    public function has(string $key): bool
    {
        return DB::table(self::TABLE)->where('dataset', $this->dataset)->where('scenario_key', $key)->exists();
    }

    public function add(string $key, string $table, ?int $id, ?string $ref = null, string $kind = 'created'): void
    {
        DB::table(self::TABLE)->insert([
            'dataset' => $this->dataset,
            'table_name' => $table,
            'record_id' => $id,
            'ref' => $ref,
            'scenario_key' => $key,
            'kind' => $kind,
            'created_at' => now(),
        ]);
    }

    // Inserts a row unless its key is already in the manifest; returns its id.
    public function insert(string $key, string $table, array $row): int
    {
        if (($id = $this->id($key)) !== null) {
            return $id;
        }
        $id = (int) DB::table($table)->insertGetId($row);
        $this->add($key, $table, $id);

        return $id;
    }

    // Record ids of one table.
    public function ids(string $table, ?string $kind = null): array
    {
        return DB::table(self::TABLE)->where('dataset', $this->dataset)->where('table_name', $table)
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->whereNotNull('record_id')->pluck('record_id')->map(fn ($v) => (int) $v)->all();
    }

    public function files(): array
    {
        return DB::table(self::TABLE)->where('dataset', $this->dataset)->whereNotNull('ref')->pluck('ref')->all();
    }

    public function counts(): array
    {
        return DB::table(self::TABLE)->where('dataset', $this->dataset)
            ->selectRaw('table_name, kind, count(*) as c')->groupBy('table_name', 'kind')
            ->orderBy('table_name')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function total(): int
    {
        return DB::table(self::TABLE)->where('dataset', $this->dataset)->count();
    }

    // id => scenario key, for one table
    public function keys(string $table): array
    {
        return DB::table(self::TABLE)->where('dataset', $this->dataset)->where('table_name', $table)
            ->whereNotNull('record_id')->pluck('scenario_key', 'record_id')->all();
    }

    public function forget(string $table, array $ids): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table(self::TABLE)->where('dataset', $this->dataset)->where('table_name', $table)->whereIn('record_id', $chunk)->delete();
        }
    }

    public function forgetFile(string $ref): void
    {
        DB::table(self::TABLE)->where('dataset', $this->dataset)->where('ref', $ref)->delete();
    }
}
