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

    public function importTracked(
        int $sourceBusinessId,
        int $targetBusinessId,
        int $createdBy,
        bool $skipExisting = true,
        array $previous = [],
        ?callable $progress = null,
        ?int $batchId = null
    ): array {
        $this->guardBusinesses($sourceBusinessId, $targetBusinessId);
        $source = $this->orderedCategories($sourceBusinessId);
        $target = ExpenseCategory::query()->where('business_id', $targetBusinessId)->get();
        $sourcePaths = $this->categoryPaths($source);
        $index = $this->buildIndex($target, $this->categoryPaths($target));
        $outcomes = collect($previous['row_outcomes'] ?? [])->keyBy('source_id');
        $createdRecords = collect($previous['created_records'] ?? [])->keyBy('target_id');
        $sourceToTarget = [];
        $current = 0;

        foreach ($source as $category) {
            $sourceId = (int) $category->id;
            $path = $sourcePaths[$sourceId] ?? $this->normalized($category->name);
            $prior = $outcomes->get($sourceId);
            if ($prior && in_array($prior['status'] ?? '', ['created', 'skipped'], true)) {
                $sourceToTarget[$sourceId] = (int) ($prior['target_id'] ?? 0) ?: null;
                $current++;
                if ($progress) $progress($current, $source->count(), $outcomes->values()->all(), $createdRecords->values()->all());
                continue;
            }

            // Recover a row committed just before a worker interruption, even if
            // its result payload had not yet been written to durable storage.
            $recovered = $batchId
                ? ExpenseCategory::where('business_id', $targetBusinessId)->where('user_import_batch_id', $batchId)
                    ->where('user_import_source_id', $sourceId)->first()
                : null;
            if ($recovered) {
                $sourceToTarget[$sourceId] = (int) $recovered->id;
                $snapshot = [
                    'target_id' => (int) $recovered->id,
                    'attributes' => $this->modelAttributes($recovered),
                    'updated_at' => (string) $recovered->updated_at,
                ];
                $outcomes->put($sourceId, [
                    'source_id' => $sourceId,
                    'source_label' => (string) $category->name,
                    'status' => 'created',
                    'target_id' => (int) $recovered->id,
                    'target_label' => (string) $recovered->name,
                    'attributes' => $snapshot['attributes'],
                    'updated_at' => $snapshot['updated_at'],
                ]);
                $createdRecords->put($recovered->id, $snapshot);
                $this->addToIndex($index, $recovered, $path, $recovered);
                $current++;
                if ($progress) $progress($current, $source->count(), $outcomes->values()->all(), $createdRecords->values()->all());
                continue;
            }

            $match = $this->findMatch($category, $path, $index);
            if ($match && $skipExisting) {
                $targetId = (int) $match['category']->id;
                $sourceToTarget[$sourceId] = $targetId;
                $outcomes->put($sourceId, [
                    'source_id' => $sourceId,
                    'source_label' => (string) $category->name,
                    'status' => 'skipped',
                    'target_id' => $targetId,
                    'target_label' => (string) $match['category']->name,
                    'matched_by' => $match['matched_by'],
                ]);
            } else {
                $parentId = null;
                if (! empty($category->parent_id)) {
                    $parentId = $sourceToTarget[(int) $category->parent_id] ?? null;
                    if ($parentId === null) {
                        $outcomes->put($sourceId, [
                            'source_id' => $sourceId,
                            'source_label' => (string) $category->name,
                            'status' => 'failed',
                            'error' => 'Parent kategori tidak berhasil dipetakan.',
                        ]);
                        $current++;
                        if ($progress) $progress($current, $source->count(), $outcomes->values()->all(), $createdRecords->values()->all());
                        continue;
                    }
                }

                try {
                    $created = DB::transaction(function () use ($category, $targetBusinessId, $parentId, $batchId, $sourceId) {
                        $attributes = [
                            'business_id' => $targetBusinessId,
                            'name' => trim((string) $category->name),
                            'code' => $this->nullableString($category->code),
                            'parent_id' => $parentId,
                        ];
                        if ($batchId) {
                            $attributes['user_import_batch_id'] = $batchId;
                            $attributes['user_import_source_id'] = $sourceId;
                        }

                        return ExpenseCategory::create($attributes)->fresh();
                    });
                    $sourceToTarget[$sourceId] = (int) $created->id;
                    $outcomes->put($sourceId, [
                        'source_id' => $sourceId,
                        'source_label' => (string) $category->name,
                        'status' => 'created',
                        'target_id' => (int) $created->id,
                        'target_label' => (string) $created->name,
                        'attributes' => $this->modelAttributes($created),
                        'updated_at' => (string) $created->updated_at,
                    ]);
                    $createdRecords->put($created->id, [
                        'target_id' => (int) $created->id,
                        'attributes' => $this->modelAttributes($created),
                        'updated_at' => (string) $created->updated_at,
                    ]);
                    $this->addToIndex($index, $created, $path, $created);
                } catch (\Throwable $exception) {
                    $outcomes->put($sourceId, [
                        'source_id' => $sourceId,
                        'source_label' => (string) $category->name,
                        'status' => 'failed',
                        'error' => mb_substr($exception->getMessage(), 0, 2000),
                    ]);
                }
            }

            $current++;
            if ($progress) $progress($current, $source->count(), $outcomes->values()->all(), $createdRecords->values()->all());
        }

        $rows = $outcomes->values()->all();

        return [
            'summary' => [
                'source_rows' => count($rows),
                'created_rows' => $outcomes->where('status', 'created')->count(),
                'skipped_rows' => $outcomes->where('status', 'skipped')->count(),
                'failed_rows' => $outcomes->where('status', 'failed')->count(),
            ],
            'row_outcomes' => $rows,
            'created_records' => $createdRecords->values()->all(),
        ];
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

    private function modelAttributes($model): array
    {
        return json_decode(json_encode($model->getAttributes(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
