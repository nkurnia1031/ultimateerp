<?php

namespace App\Http\Controllers;

use App\Support\ChangeLogCatalog;
use Illuminate\Http\Request;

class GuideController extends Controller
{
    public function index(Request $request, ChangeLogCatalog $catalog)
    {
        $query = trim((string) $request->input('q', ''));
        $guides = $catalog->guidesFor($request->user());

        if ($query !== '') {
            $needle = strtolower($query);
            $guides = $guides->filter(function (array $guide) use ($needle): bool {
                $haystack = strtolower(implode(' ', [
                    $guide['title'] ?? '',
                    $guide['summary'] ?? '',
                    $guide['category'] ?? '',
                    $guide['audience'] ?? '',
                ]));

                return str_contains($haystack, $needle);
            })->values();
        }

        return view('guides.index', [
            'guides' => $guides,
            'query' => $query,
        ]);
    }

    public function show(string $slug, Request $request, ChangeLogCatalog $catalog)
    {
        $guide = $catalog->findGuideFor($slug, $request->user());

        abort_if($guide === null, 404);

        return view('guides.show', [
            'guide' => $guide,
            'links' => $catalog->visibleLinks($guide, $request->user()),
            'relatedGuides' => $catalog->guidesFor($request->user())
                ->where('category', $guide['category'] ?? null)
                ->reject(fn (array $item): bool => ($item['slug'] ?? '') === $slug)
                ->take(4)
                ->values(),
        ]);
    }
}
