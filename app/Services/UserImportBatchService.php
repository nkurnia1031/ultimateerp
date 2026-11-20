<?php

namespace App\Services;

use App\UserImportBatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class UserImportBatchService
{
    private const PAYLOAD_FIELDS = [
        'source_snapshot' => 'source_snapshot_path',
        'preview' => 'preview_path',
        'results' => 'results_path',
    ];

    public function create(array $attributes): UserImportBatch
    {
        return UserImportBatch::create($attributes);
    }

    public function findForBusiness(int $batchId, int $businessId, string $importerKey): UserImportBatch
    {
        return UserImportBatch::where('business_id', $businessId)
            ->where('importer_key', $importerKey)
            ->findOrFail($batchId);
    }

    public function storePayload(UserImportBatch $batch, string $key, array $payload): string
    {
        if (! isset(self::PAYLOAD_FIELDS[$key])) {
            throw new \InvalidArgumentException('Jenis payload batch import tidak dikenal.');
        }
        if ($key === 'source_snapshot' && filled($batch->source_snapshot_path)) {
            throw new \RuntimeException('Snapshot sumber import bersifat immutable.');
        }

        $relativePath = 'user-imports/'.$batch->id.'/'.$key.'.json';
        $contents = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (! Storage::disk('local')->put($relativePath, $contents)) {
            throw new \RuntimeException('Payload batch import tidak dapat disimpan ke storage privat.');
        }

        $batch->{self::PAYLOAD_FIELDS[$key]} = $relativePath;
        $batch->save();

        return $relativePath;
    }

    public function readPayload(UserImportBatch $batch, string $key): array
    {
        if (! isset(self::PAYLOAD_FIELDS[$key])) {
            throw new \InvalidArgumentException('Jenis payload batch import tidak dikenal.');
        }

        $path = $batch->{self::PAYLOAD_FIELDS[$key]};
        if (! $path || ! Storage::disk('local')->exists($path)) {
            throw new \RuntimeException('Data batch import tidak tersedia.');
        }

        return json_decode(Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function dataTable(Request $request, int $businessId, string $importerKey): array
    {
        $baseQuery = UserImportBatch::where('business_id', $businessId)->where('importer_key', $importerKey);
        $recordsTotal = (clone $baseQuery)->count();
        $filteredQuery = clone $baseQuery;
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $filteredQuery->where(function (Builder $query) use ($search) {
                $query->where('file_name', 'like', '%'.$search.'%')
                    ->orWhere('status', 'like', '%'.$search.'%')
                    ->orWhere('last_message', 'like', '%'.$search.'%')
                    ->orWhere('error_message', 'like', '%'.$search.'%');
            });
        }
        $status = trim((string) $request->input('status', ''));
        if ($status !== '') {
            $filteredQuery->where('status', $status);
        }
        $recordsFiltered = (clone $filteredQuery)->count();
        $length = min(100, max(1, (int) $request->input('length', 25)));
        $start = max(0, (int) $request->input('start', 0));
        $batches = $filteredQuery->orderByDesc('created_at')->orderByDesc('id')->skip($start)->take($length)->get();

        return [
            'draw' => (int) $request->input('draw', 0),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $batches->map(fn (UserImportBatch $batch) => $this->tableRow($batch))->values(),
        ];
    }

    public function workbookDownload(UserImportBatch $batch)
    {
        $snapshot = $this->readPayload($batch, 'source_snapshot');
        $sheets = $snapshot['sheets'] ?? [];
        if ($sheets === []) {
            abort(404, 'Snapshot workbook tidak memiliki sheet yang dapat dipulihkan.');
        }

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);
        foreach ($sheets as $sheetData) {
            $worksheet = $spreadsheet->createSheet();
            $worksheet->setTitle($this->safeSheetTitle((string) ($sheetData['title'] ?? 'Data'), $spreadsheet));
            foreach (($sheetData['rows'] ?? []) as $rowKey => $cells) {
                $rowNumber = is_numeric($rowKey) ? (int) $rowKey : 0;
                if ($rowNumber < 1) {
                    $rowNumber = (int) $rowKey + 1;
                }
                if ($rowNumber < 1 || ! is_array($cells)) {
                    continue;
                }
                foreach ($cells as $column => $value) {
                    if ($value === null || $value === '' || ! is_string($column) || ! preg_match('/^[A-Z]+$/i', $column)) {
                        continue;
                    }
                    $coordinate = strtoupper($column).$rowNumber;
                    $type = is_bool($value)
                        ? DataType::TYPE_BOOL
                        : (is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                    $worksheet->setCellValueExplicit($coordinate, $value, $type);
                }
            }
        }
        $spreadsheet->setActiveSheetIndex(0);

        $baseName = pathinfo((string) $batch->file_name, PATHINFO_FILENAME);
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $baseName) ?: 'import';
        $fileName = $safeName.'_dipulihkan.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function tableRow(UserImportBatch $batch): array
    {
        $summary = json_decode((string) $batch->summary_json, true) ?: [];

        return [
            'id' => $batch->id,
            'file_name' => $batch->file_name ?: 'Impor tanpa file',
            'status' => $batch->status,
            'stage' => $batch->stage,
            'progress_current' => (int) $batch->progress_current,
            'progress_total' => (int) $batch->progress_total,
            'progress_percent' => (int) $batch->progress_percent,
            'summary' => $summary,
            'last_message' => $batch->last_message,
            'error_message' => $batch->error_message,
            'created_at' => optional($batch->created_at)->format('Y-m-d H:i:s'),
            'updated_at' => optional($batch->updated_at)->format('Y-m-d H:i:s'),
            'has_snapshot' => $batch->hasSourceSnapshot(),
            'can_preview' => $batch->status === 'preview_ready',
            'can_revert' => in_array($batch->status, ['imported', 'partial'], true),
            'can_retry' => in_array($batch->status, ['failed', 'partial'], true)
                && (bool) $batch->preview_path
                && (int) ($summary['source_rows'] ?? 0) > 0,
            'active' => $batch->isActive(),
        ];
    }

    private function safeSheetTitle(string $title, Spreadsheet $spreadsheet): string
    {
        $title = preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', trim($title)) ?: 'Data';
        $title = mb_substr($title, 0, 31, 'UTF-8');
        $candidate = $title;
        $suffix = 1;
        while ($spreadsheet->sheetNameExists($candidate)) {
            $marker = '_'.$suffix++;
            $candidate = mb_substr($title, 0, 31 - mb_strlen($marker, 'UTF-8'), 'UTF-8').$marker;
        }

        return $candidate;
    }
}
