<?php

namespace Modules\Project\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Modules\Project\Entities\Project;
use Modules\Project\Entities\ProjectTransaction;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProjectWorkbookExporter
{
    private const DATA_FIRST_ROW = 5;

    private const DATA_LAST_ROW = 204;

    protected $template;

    public function __construct(ProjectImportTemplate $template)
    {
        $this->template = $template;
    }

    public function build(Project $project, ?ProjectTransaction $invoice, array $references): Spreadsheet
    {
        $workbook = $this->template->build($references);
        $taskKeys = $this->taskKeys($project->tasks);

        $this->writeForm($workbook->getSheetByName('Project'), $this->projectValues($project, $references));
        $this->writeTable($workbook->getSheetByName('Tasks'), $this->taskRows($project, $references, $taskKeys));
        $this->writeTable($workbook->getSheetByName('Time Logs'), $this->timeLogRows($project, $references, $taskKeys));
        $this->writeForm($workbook->getSheetByName('Invoice'), $this->invoiceValues($invoice, $references));
        $this->writeTable($workbook->getSheetByName('Invoice Lines'), $this->invoiceLineRows($invoice, $references, $taskKeys));

        $workbook->setActiveSheetIndexByName('Project');

        return $workbook;
    }

    private function projectValues(Project $project, array $references): array
    {
        $settings = (array) ($project->settings ?: []);

        return [
            'project_name' => $project->name,
            'customer_ref' => $this->reference($references['customers'] ?? [], 'C', $project->contact_id),
            'status' => $project->status,
            'lead_ref' => $this->reference($references['users'] ?? [], 'U', $project->lead_id),
            'member_refs' => $this->userReferences($project->members, $references),
            'start_date' => $this->dateSerial($project->start_date),
            'end_date' => $this->dateSerial($project->end_date),
            'description' => $project->description,
            'category_names' => $this->names($project->categories),
            'enable_timelog' => $this->yesNo($settings['enable_timelog'] ?? true),
            'enable_invoice' => $this->yesNo($settings['enable_invoice'] ?? true),
            'enable_notes_documents' => $this->yesNo($settings['enable_notes_documents'] ?? true),
            'members_crud_task' => $this->yesNo($settings['members_crud_task'] ?? false),
            'members_crud_note' => $this->yesNo($settings['members_crud_note'] ?? false),
            'members_crud_timelog' => $this->yesNo($settings['members_crud_timelog'] ?? false),
            'task_view' => $settings['task_view'] ?? 'list_view',
            'task_id_prefix' => $settings['task_id_prefix'] ?? '#',
        ];
    }

    private function taskRows(Project $project, array $references, array $taskKeys): array
    {
        return $project->tasks->map(function ($task) use ($references, $taskKeys) {
            return [
                'task_key' => $taskKeys[$task->id],
                'subject' => $task->subject,
                'description' => $task->description,
                'start_date' => $this->dateSerial($task->start_date),
                'due_date' => $this->dateSerial($task->due_date),
                'priority' => $task->priority,
                'status' => $task->status,
                'member_refs' => $this->userReferences($task->members, $references),
                'tag_names' => $this->names($task->tags),
                'custom_field_1' => $task->custom_field_1,
                'custom_field_2' => $task->custom_field_2,
                'custom_field_3' => $task->custom_field_3,
                'custom_field_4' => $task->custom_field_4,
            ];
        })->all();
    }

    private function timeLogRows(Project $project, array $references, array $taskKeys): array
    {
        return $project->timeLogs->map(function ($timeLog) use ($references, $taskKeys) {
            return [
                'task_key' => $timeLog->project_task_id ? ($taskKeys[$timeLog->project_task_id] ?? '') : '',
                'worker_ref' => $this->reference($references['users'] ?? [], 'U', $timeLog->user_id),
                'work_date' => $this->dateSerial($timeLog->start_datetime),
                'shift' => $timeLog->shift,
                'start_1' => $this->timeSerial($timeLog->start_datetime),
                'end_1' => $this->timeSerial($timeLog->end_datetime),
                'start_2' => $this->timeSerial($timeLog->second_start_datetime),
                'end_2' => $this->timeSerial($timeLog->second_end_datetime),
                'note' => $timeLog->note,
                'tag_names' => $this->names($timeLog->tags),
            ];
        })->all();
    }

    private function invoiceValues(?ProjectTransaction $invoice, array $references): array
    {
        if ($invoice === null) {
            return ['generate_invoice' => 'tidak'];
        }

        return [
            'generate_invoice' => 'ya',
            'invoice_title' => $invoice->pjt_title,
            'customer_ref' => $this->reference($references['customers'] ?? [], 'C', $invoice->contact_id),
            'location_ref' => $this->reference($references['locations'] ?? [], 'L', $invoice->location_id),
            'invoice_date' => $this->dateSerial($invoice->transaction_date),
            'status' => in_array($invoice->status, ['draft', 'final'], true) ? $invoice->status : 'draft',
            'invoice_scheme_ref' => $references['default_invoice_scheme'] ?? '',
            'pay_term_number' => $invoice->pay_term_number,
            'pay_term_type' => $invoice->pay_term_type,
            'discount_type' => $invoice->discount_type ?: 'fixed',
            'discount_amount' => (float) ($invoice->discount_amount ?? 0),
            'staff_note' => $invoice->staff_note,
            'additional_notes' => $invoice->additional_notes,
        ];
    }

    private function invoiceLineRows(?ProjectTransaction $invoice, array $references, array $taskKeys): array
    {
        if ($invoice === null) {
            return [];
        }

        return $invoice->invoiceLines->map(function ($line) use ($references, $taskKeys) {
            return [
                'task_key' => $line->project_task_id ? ($taskKeys[$line->project_task_id] ?? '') : '',
                'item_name' => $line->task,
                'quantity_source' => 'manual',
                'manual_quantity' => (float) $line->quantity,
                'rate' => (float) $line->rate,
                'tax_ref' => $this->reference($references['taxes'] ?? [], 'T', $line->tax_rate_id),
                'description' => $line->description,
            ];
        })->all();
    }

    private function taskKeys(Collection $tasks): array
    {
        $keys = [];
        $used = [];

        foreach ($tasks as $task) {
            $candidate = trim((string) $task->task_id);
            if ($candidate === '' || isset($used[strtolower($candidate)])) {
                $candidate = 'TASK-' . $task->id;
            }
            while (isset($used[strtolower($candidate)])) {
                $candidate .= '-' . $task->id;
            }

            $keys[$task->id] = $candidate;
            $used[strtolower($candidate)] = true;
        }

        return $keys;
    }

    private function writeForm(Worksheet $sheet, array $values): void
    {
        for ($row = self::DATA_FIRST_ROW; $row <= $sheet->getHighestDataRow(); $row++) {
            $key = trim((string) $sheet->getCell("E{$row}")->getValue());
            if ($key !== '' && array_key_exists($key, $values)) {
                $this->writeCell($sheet, "B{$row}", $values[$key]);
            }
        }
    }

    private function writeTable(Worksheet $sheet, array $rows): void
    {
        $capacity = self::DATA_LAST_ROW - self::DATA_FIRST_ROW + 1;
        if (count($rows) > $capacity) {
            throw new InvalidArgumentException(
                "Sheet {$sheet->getTitle()} memiliki " . count($rows) . " baris; template mendukung maksimal {$capacity} baris."
            );
        }

        $headers = [];
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        for ($column = 1; $column <= $lastColumn; $column++) {
            $key = trim((string) $sheet->getCellByColumnAndRow($column, 3)->getValue());
            if ($key !== '') {
                $headers[$key] = Coordinate::stringFromColumnIndex($column);
            }
        }

        foreach ($rows as $index => $values) {
            $row = self::DATA_FIRST_ROW + $index;
            foreach ($values as $key => $value) {
                if (isset($headers[$key])) {
                    $this->writeCell($sheet, $headers[$key] . $row, $value);
                }
            }
        }
    }

    private function writeCell(Worksheet $sheet, string $coordinate, $value): void
    {
        if (is_string($value)) {
            $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);

            return;
        }

        $sheet->setCellValue($coordinate, $value);
    }

    private function reference(array $items, string $prefix, $id): string
    {
        if (empty($id)) {
            return '';
        }

        $needle = $prefix . (int) $id;
        foreach ($items as $item) {
            $label = is_array($item) ? ($item['label'] ?? '') : $item;
            if (preg_match('/^' . preg_quote($needle, '/') . '\\b/i', (string) $label)) {
                return (string) $label;
            }
        }

        return '';
    }

    private function userReferences(Collection $users, array $references): string
    {
        return $users->map(function ($user) use ($references) {
            return $this->reference($references['users'] ?? [], 'U', $user->id);
        })->filter()->implode('; ');
    }

    private function names(Collection $items): string
    {
        return $items->pluck('name')->filter()->implode('; ');
    }

    private function yesNo($value): string
    {
        return $value ? 'ya' : 'tidak';
    }

    private function dateSerial($value): ?float
    {
        if (empty($value)) {
            return null;
        }

        return ExcelDate::PHPToExcel(Carbon::parse($value)->startOfDay()->toDateTime());
    }

    private function timeSerial($value): ?float
    {
        if (empty($value)) {
            return null;
        }

        $time = Carbon::parse($value);

        return ($time->hour * 3600 + $time->minute * 60 + $time->second) / 86400;
    }
}
