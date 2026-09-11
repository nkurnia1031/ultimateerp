<?php

namespace App\Services;

use App\ExpenseCategory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ExpenseCategoryBusinessImportService
{
    private const PREVIEW_LIMIT = 100;

    public function preview(int $sourceBusinessId, int $targetBusinessId): array
    {
        $this->guardBusinesses($sourceBusinessId, $targetBusinessId);

        $source = $this->orderedCategories($sourceBusinessId);
        $target = ExpenseCategory::query()
            ->where('business_id', $targetBusinessId)
            ->orderBy('id')
            ->get();
        $sourcePaths = $this->categoryPaths($source);
        $index = $this->buildIndex($target, $this->categoryPaths($target));
        $rows = [];
        $counts = ['total' => $source->count(), 'new' => 0, 'existing' => 0];

        foreach ($source as $category) {
            $path = $sourcePaths[$category->id] ?? $this->normalized($category->name);
            $match = $this->findMatch($category, $path, $index);
            $status = $match ? 'existing' : 'new';
            $counts[$status]++;

            if (count($rows) < self::PREVIEW_LIMIT) {
                $rows[] = $this->previewRow($category, $path, $status, $match);
            }

            // Treat duplicate source rows as existing in the same preview.
            if (! $match) {
                $this->addToIndex($index, $category, $path, null);
            }
        }

        return [
            'source_business_id' => $sourceBusinessId,
            'target_business_id' => $targetBusinessId,
            'counts' => $counts,
            'rows' => $rows,
            'truncated' => $source->count() > count($rows),
        ];
    }

    public function import(int $sourceBusinessId, int $targetBusinessId, int $createdBy, bool $skipExisting = true): array
    {
        $this->guardBusinesses($sourceBusinessId, $targetBusinessId);

        return DB::transaction(function () use ($sourceBusinessId, $targetBusinessId, $createdBy, $skipExisting) {
            $source = $this->orderedCategories($sourceBusinessId, true);
            $target = ExpenseCategory::query()
                ->where('business_id', $targetBusinessId)
                ->lockForUpdate()
                ->get();
            $sourcePaths = $this->categoryPaths($source);
            $index = $this->buildIndex($target, $this->categoryPaths($target));
            $sourceToTarget = [];
            $created = 0;
            $skipped = 0;

            foreach ($source as $category) {
                $path = $sourcePaths[$category->id] ?? $this->normalized($category->name);
                $match = $this->findMatch($category, $path, $index);

                if ($match && $skipExisting) {
                    $sourceToTarget[$category->id] = (int) $match['category']->id;
                    $skipped++;
                    continue;
                }

                $parentId = null;
                if (! empty($category->parent_id)) {
                    $parentId = $sourceToTarget[(int) $category->parent_id] ?? null;
                    if ($parentId === null) {
                        throw new RuntimeException('Parent kategori tidak dapat dipetakan untuk "'.$category->name.'".');
                    }
                }

                $newCategory = ExpenseCategory::create([
                    'business_id' => $targetBusinessId,
                    'name' => trim((string) $category->name),
                    'code' => $this->nullableString($category->code),
                    'parent_id' => $parentId,
                ]);
                $sourceToTarget[$category->id] = (int) $newCategory->id;
                $this->addToIndex($index, $newCategory, $path, $newCategory);
                $created++;
            }

            return [
                'total' => $source->count(),
                'created' => $created,
                'skipped' => $skipped,
            ];
        });
    }

    private function guardBusinesses(int $sourceBusinessId, int $targetBusinessId): void
    {
        if ($sourceBusinessId < 1 || $targetBusinessId < 1 || $sourceBusinessId === $targetBusinessId) {
            throw new RuntimeException('Bisnis sumber dan tujuan harus berbeda.');
        }
    }

    private function orderedCategories(int $businessId, bool $lock = false)
    {
        $query = ExpenseCategory::query()
            ->where('business_id', $businessId)
            ->with('parent')
            ->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        $categories = $query->get();
        $byId = $categories->keyBy('id');
        $depth = [];
        $visiting = [];

        $getDepth = function ($categoryId) use (&$getDepth, &$depth, &$visiting, $byId): int {
            $categoryId = (int) $categoryId;
            if (isset($depth[$categoryId])) {
                return $depth[$categoryId];
            }
            if (isset($visiting[$categoryId])) {
                return 0;
            }
            $visiting[$categoryId] = true;
            $category = $byId->get($categoryId);
            $parentDepth = $category && $category->parent_id && $byId->has($category->parent_id)
                ? $getDepth($category->parent_id)
                : -1;
            unset($visiting[$categoryId]);

            return $depth[$categoryId] = $parentDepth + 1;
        };

        return $categories->sortBy(fn ($category) => [$getDepth($category->id), (int) $category->id])->values();
    }

    private function categoryPaths($categories): array
    {
        $byId = $categories->keyBy('id');
        $paths = [];
        $visiting = [];
        $pathFor = function ($categoryId) use (&$pathFor, &$paths, &$visiting, $byId): string {
            $categoryId = (int) $categoryId;
            if (isset($paths[$categoryId])) {
                return $paths[$categoryId];
            }
            if (isset($visiting[$categoryId])) {
                return '';
            }
            $category = $byId->get($categoryId);
            if (! $category) {
                return '';
            }
            $visiting[$categoryId] = true;
            $parentPath = $category->parent_id ? $pathFor($category->parent_id) : '';
            unset($visiting[$categoryId]);
            $name = $this->normalized($category->name);

            return $paths[$categoryId] = $parentPath !== '' ? $parentPath.' > '.$name : $name;
        };

        foreach ($categories as $category) {
            $pathFor($category->id);
        }

        return $paths;
    }

    private function buildIndex($categories, array $paths): array
    {
        $index = ['path' => [], 'code_name' => []];
        foreach ($categories as $category) {
            $this->addToIndex($index, $category, $paths[$category->id] ?? '', $category);
        }

        return $index;
    }

    private function addToIndex(array &$index, $category, string $path, $matchedCategory): void
    {
        $pathKey = $this->normalized($path);
        if ($pathKey !== '' && ! isset($index['path'][$pathKey])) {
            $index['path'][$pathKey] = ['category' => $matchedCategory ?: $category, 'matched_by' => 'hierarki'];
        }
        $code = $this->normalized($category->code);
        $name = $this->normalized($category->name);
        $codeKey = $code !== '' ? $code.'|'.$name : '';
        if ($codeKey !== '' && ! isset($index['code_name'][$codeKey])) {
            $index['code_name'][$codeKey] = ['category' => $matchedCategory ?: $category, 'matched_by' => 'kode'];
        }
    }

    private function findMatch($category, string $path, array $index): ?array
    {
        $pathKey = $this->normalized($path);
        if ($pathKey !== '' && isset($index['path'][$pathKey])) {
            return $index['path'][$pathKey];
        }
        $code = $this->normalized($category->code);
        $name = $this->normalized($category->name);
        $codeKey = $code !== '' ? $code.'|'.$name : '';

        return $codeKey !== '' ? ($index['code_name'][$codeKey] ?? null) : null;
    }

    private function previewRow($category, string $path, string $status, ?array $match): array
    {
        return [
            'id' => (int) $category->id,
            'name' => $category->name,
            'code' => $category->code,
            'parent_name' => $category->parent_id ? optional($category->parent)->name : null,
            'path' => $path,
            'status' => $status,
            'matched_by' => $match['matched_by'] ?? null,
            'matched_category_id' => isset($match['category']) ? (int) $match['category']->id : null,
        ];
    }

    private function normalized($value): string
    {
        $value = trim((string) $value);

        return $value === '' ? '' : mb_strtolower(preg_replace('/\s+/', ' ', $value));
    }

    private function nullableString($value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
