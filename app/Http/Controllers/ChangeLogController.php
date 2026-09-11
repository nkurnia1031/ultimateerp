<?php

namespace App\Http\Controllers;

use App\ChangeLogRead;
use App\Support\ChangeLogCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ChangeLogController extends Controller
{
    public function index(Request $request, ChangeLogCatalog $catalog)
    {
        return view('change-log.index', [
            'releases' => $catalog->publishedFor($request->user()),
            'guides' => $catalog->guidesFor($request->user()),
        ]);
    }

    public function show(string $slug, Request $request, ChangeLogCatalog $catalog)
    {
        $release = $catalog->findPublishedFor($slug, $request->user());

        abort_if($release === null, 404);

        return view('change-log.show', [
            'release' => $release,
            'links' => $catalog->visibleLinks($release, $request->user()),
            'guides' => $catalog->guidesForRelease($release['slug'], $request->user()),
        ]);
    }

    public function markRead(Request $request, ChangeLogCatalog $catalog)
    {
        $validated = $request->validate([
            'release_ids' => ['required', 'array', 'min:1'],
            'release_ids.*' => ['required', 'string', 'max:100'],
        ]);

        $businessId = (int) session('user.business_id');
        if ($businessId <= 0) {
            abort(403);
        }

        $releaseIds = collect($validated['release_ids'])
            ->map(fn ($releaseId): string => trim((string) $releaseId))
            ->filter()
            ->unique()
            ->values();
        $eligibleIds = $catalog->publishedReleaseIdsFor($request->user());
        $invalidIds = $releaseIds->diff($eligibleIds)->values();

        if ($invalidIds->isNotEmpty()) {
            throw ValidationException::withMessages([
                'release_ids' => 'Satu atau beberapa rilis tidak tersedia untuk akun atau business aktif ini.',
            ]);
        }

        $now = now();
        $rows = $releaseIds->map(fn (string $releaseId): array => [
            'user_id' => $request->user()->id,
            'business_id' => $businessId,
            'release_id' => $releaseId,
            'read_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        DB::transaction(function () use ($rows): void {
            ChangeLogRead::query()->upsert(
                $rows,
                ['user_id', 'business_id', 'release_id'],
                ['read_at', 'updated_at']
            );
        });

        return response()->json([
            'success' => true,
            'read_release_ids' => $releaseIds->all(),
        ]);
    }
}
