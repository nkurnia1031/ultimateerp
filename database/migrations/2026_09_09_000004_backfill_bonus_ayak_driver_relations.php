<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $users = DB::table('users')->select('id', 'business_id', 'surname', 'first_name', 'last_name', 'username')->get();
        foreach (['bonus_ayak', 'bonus_ayak_kasbon'] as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }
            DB::table($table)->whereNull('driver_id')->orderBy('id')->chunkById(250, function ($rows) use ($users, $table) {
                foreach ($rows as $row) {
                    $needle = $this->normalize($row->nama_operator);
                    $matches = $users->filter(function ($user) use ($needle) {
                        return $this->matches($needle, trim(implode(' ', array_filter([$user->surname, $user->first_name, $user->last_name]))))
                            || $this->matches($needle, $user->username);
                    });
                    if ($matches->count() !== 1) {
                        continue;
                    }
                    $driver = $matches->first();
                    $updates = ['driver_id' => $driver->id];
                    if (empty($row->business_id)) {
                        $updates['business_id'] = $driver->business_id;
                    }
                    DB::table($table)->where('id', $row->id)->update($updates);
                }
            });
        }
    }

    public function down(): void
    {
        // Backfill is intentionally not reversed; the relation is additive and reverting it would lose valid mappings.
    }

    private function matches(string $needle, ?string $candidate): bool
    {
        $needle = $this->normalize(preg_replace('/^(mr|mrs|miss|ms)\s+/i', '', $needle));
        $candidate = $this->normalize(preg_replace('/^(mr|mrs|miss|ms)\s+/i', '', (string) $candidate));
        if ($needle === '' || $candidate === '') {
            return false;
        }
        $needleTokens = array_filter(explode(' ', $needle));
        $candidateTokens = array_filter(explode(' ', $candidate));

        return $needle === $candidate || (count($needleTokens) > 0 && count(array_intersect($needleTokens, $candidateTokens)) === count($needleTokens));
    }

    private function normalize(?string $value): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $value ?? '')));
    }
};
