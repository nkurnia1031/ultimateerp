<?php

namespace Modules\Project\Services;

use App\BusinessLocation;
use App\Category;
use App\Contact;
use App\InvoiceScheme;
use App\Tag;
use App\TaxRate;
use App\User;
use App\Utils\TransactionUtil;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Project\Entities\Project;
use Modules\Project\Entities\ProjectTask;
use Modules\Project\Entities\ProjectTimeLog;
use Modules\Project\Entities\ProjectTransaction;
use Modules\Project\Utils\ProjectUtil;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProjectWorkbookImporter
{
    private const MAX_DATA_ROW = 204;

    protected $projectUtil;

    protected $transactionUtil;

    protected $businessId;

    protected $importedBy;

    protected $users;

    protected $usersByEmail;

    protected $customers;

    protected $locations;

    protected $taxes;

    protected $invoiceSchemes;

    protected $categories;

    protected $tags;

    protected $rowOutcomes = [];

    protected $progressListener;

    protected $progressCurrent = 0;

    protected $progressTotal = 1;

    public function __construct(ProjectUtil $projectUtil, TransactionUtil $transactionUtil)
    {
        $this->projectUtil = $projectUtil;
        $this->transactionUtil = $transactionUtil;
    }

    public function import(string $path, int $businessId, int $userId, ?callable $progressListener = null): array
    {
        $this->businessId = $businessId;
        $this->importedBy = $userId;
        $this->rowOutcomes = [];
        $this->progressListener = $progressListener;
        $this->progressCurrent = 0;
        $this->loadLookups();

        $workbook = IOFactory::load($path);
        $this->validateWorkbook($workbook);
        $invoiceValues = $this->readForm($workbook->getSheetByName('Invoice'));
        $this->progressTotal = 1
            + count($this->readTable($workbook->getSheetByName('Tasks'), ['task_key', 'subject']))
            + count($this->readTable($workbook->getSheetByName('Time Logs'), ['task_key', 'worker_ref', 'work_date', 'shift', 'start_1', 'end_1', 'start_2', 'end_2', 'note', 'tag_names']));
        if ($this->yesNoValue($invoiceValues['generate_invoice'] ?? null, false)) {
            $this->progressTotal++;
            $this->progressTotal += count($this->readTable(
                $workbook->getSheetByName('Invoice Lines'),
                ['task_key', 'item_name', 'quantity_source', 'manual_quantity', 'rate', 'tax_ref', 'description']
            ));
        }

        return DB::transaction(function () use ($workbook) {
            $projectValues = $this->readForm($workbook->getSheetByName('Project'));
            $project = $this->createProject($projectValues);
            $this->reportProgress('project', 2, 'Project utama berhasil divalidasi dan dibuat.');
            $tasks = $this->createTasks($workbook->getSheetByName('Tasks'), $project);
            $timeLogCount = $this->createTimeLogs($workbook->getSheetByName('Time Logs'), $project, $tasks);
            $invoice = $this->createInvoice(
                $this->readForm($workbook->getSheetByName('Invoice')),
                $workbook->getSheetByName('Invoice Lines'),
                $project,
                $tasks
            );

            $taskRecords = collect($tasks)->values();
            $taskSnapshots = $taskRecords->map(fn (ProjectTask $task) => [
                'id' => (int) $task->id,
                'updated_at' => (string) $task->fresh()->updated_at,
                'attributes' => $task->fresh()->getAttributes(),
            ])->all();
            $timeLogSnapshots = $project->timeLogs()->get()->map(fn (ProjectTimeLog $timeLog) => [
                'id' => (int) $timeLog->id,
                'updated_at' => (string) $timeLog->updated_at,
                'attributes' => $timeLog->getAttributes(),
            ])->all();
            $this->rowOutcomes[] = [
                'sheet' => 'Project',
                'source_row' => 2,
                'status' => 'created',
                'record_type' => 'project',
                'record_id' => (int) $project->id,
            ];
            if ($invoice) {
                $this->rowOutcomes[] = [
                    'sheet' => 'Invoice',
                    'source_row' => 2,
                    'status' => 'created',
                    'record_type' => 'invoice',
                    'record_id' => (int) $invoice->id,
                ];
                $this->reportProgress('invoice', 2, 'Invoice Project selesai dibuat.');
            }

            return [
                'project' => $project,
                'tasks' => count($tasks),
                'time_logs' => $timeLogCount,
                'invoice' => $invoice,
                'task_ids' => $taskRecords->map(fn (ProjectTask $task) => (int) $task->id)->all(),
                'tasks_snapshot' => $taskSnapshots,
                'time_log_ids' => array_column($timeLogSnapshots, 'id'),
                'time_logs_snapshot' => $timeLogSnapshots,
                'source_rows' => count($this->rowOutcomes),
                'row_outcomes' => $this->rowOutcomes,
            ];
        });
    }

    private function validateWorkbook(Spreadsheet $workbook): void
    {
        foreach (['Project', 'Tasks', 'Time Logs', 'Invoice', 'Invoice Lines', 'Referensi'] as $sheetName) {
            if ($workbook->getSheetByName($sheetName) === null) {
                throw new InvalidArgumentException("Sheet '{$sheetName}' tidak ditemukan. Gunakan template resmi dari menu Project.");
            }
        }

        $version = trim((string) $workbook->getSheetByName('Referensi')->getCell('T2')->getValue());
        if ($version !== ProjectImportTemplate::VERSION) {
            throw new InvalidArgumentException('Versi template tidak dikenali. Unduh template terbaru dari menu Project.');
        }
    }

    private function loadLookups(): void
    {
        $this->users = User::where('business_id', $this->businessId)->get()->keyBy('id');
        $this->usersByEmail = $this->users->filter(function ($user) {
            return ! empty($user->email);
        })->keyBy(function ($user) {
            return strtolower(trim($user->email));
        });
        $allowedCustomerIds = Contact::customersDropdown($this->businessId, false)->keys();
        $this->customers = Contact::where('business_id', $this->businessId)
            ->whereIn('id', $allowedCustomerIds)
            ->get()
            ->keyBy('id');
        $locationQuery = BusinessLocation::where('business_id', $this->businessId);
        $permittedLocations = auth()->user()->permitted_locations();
        if ($permittedLocations !== 'all') {
            $locationQuery->whereIn('id', $permittedLocations);
        }
        $this->locations = $locationQuery->get()->keyBy('id');
        $this->taxes = TaxRate::where('business_id', $this->businessId)->get()->keyBy('id');
        $this->invoiceSchemes = InvoiceScheme::where('business_id', $this->businessId)->get()->keyBy('id');
        $this->categories = Category::where('business_id', $this->businessId)
            ->where('category_type', 'project')
            ->get();
        $this->tags = Tag::where('business_id', $this->businessId)->get();
    }

    private function createProject(array $values): Project
    {
        $name = $this->stringValue($values['project_name'] ?? null);
        if ($name === '') {
            throw new InvalidArgumentException('Project: Nama Project wajib diisi.');
        }

        $status = $this->enumValue($values['status'] ?? null, Project::statusDropdown(), 'not_started', 'Project: Status');
        $leadId = $this->resolveUserId($values['lead_ref'] ?? null, true, 'Project: Project Lead');
        $leadId = $leadId ?: $this->importedBy;
        $customerId = $this->resolveReferenceId($values['customer_ref'] ?? null, 'C', $this->customers, false, 'Project: Customer');
        $startDate = $this->dateValue($values['start_date'] ?? null, false, 'Project: Tanggal Mulai');
        $endDate = $this->dateValue($values['end_date'] ?? null, false, 'Project: Tanggal Selesai');

        if ($startDate !== null && $endDate !== null && $endDate->lt($startDate)) {
            throw new InvalidArgumentException('Project: Tanggal Selesai tidak boleh sebelum Tanggal Mulai.');
        }

        $settings = [
            'enable_timelog' => $this->yesNoValue($values['enable_timelog'] ?? null, true),
            'enable_invoice' => $this->yesNoValue($values['enable_invoice'] ?? null, true),
            'enable_notes_documents' => $this->yesNoValue($values['enable_notes_documents'] ?? null, true),
            'members_crud_task' => $this->yesNoValue($values['members_crud_task'] ?? null, false),
            'members_crud_note' => $this->yesNoValue($values['members_crud_note'] ?? null, false),
            'members_crud_timelog' => $this->yesNoValue($values['members_crud_timelog'] ?? null, false),
            'task_view' => $this->enumValue(
                $values['task_view'] ?? null,
                ['list_view' => 'list_view', 'kanban' => 'kanban'],
                'list_view',
                'Project: Tampilan Task'
            ),
            'task_id_prefix' => $this->stringValue($values['task_id_prefix'] ?? null) ?: '#',
        ];

        $project = Project::create([
            'business_id' => $this->businessId,
            'name' => $name,
            'contact_id' => $customerId,
            'status' => $status,
            'lead_id' => $leadId,
            'start_date' => $startDate ? $startDate->format('Y-m-d H:i:s') : null,
            'end_date' => $endDate ? $endDate->format('Y-m-d H:i:s') : null,
            'description' => $this->nullableString($values['description'] ?? null),
            'created_by' => $this->importedBy,
            'settings' => $settings,
        ]);

        $memberIds = $this->resolveUserList($values['member_refs'] ?? null, 'Project: Anggota Project');
        $memberIds[] = $leadId;
        $project->members()->sync(array_values(array_unique($memberIds)));
        $project->categories()->sync($this->resolveNameList($values['category_names'] ?? null, $this->categories, 'Project: Kategori'));

        return $project;
    }

    private function createTasks(Worksheet $sheet, Project $project): array
    {
        $tasks = [];
        foreach ($this->readTable($sheet, ['task_key', 'subject']) as $rowNumber => $row) {
            $taskKey = $this->stringValue($row['task_key'] ?? null);
            $subject = $this->stringValue($row['subject'] ?? null);

            if ($taskKey === '' || $subject === '') {
                throw new InvalidArgumentException("Tasks baris {$rowNumber}: Task Key dan Judul Task wajib diisi.");
            }
            $normalizedKey = strtolower($taskKey);
            if (isset($tasks[$normalizedKey])) {
                throw new InvalidArgumentException("Tasks baris {$rowNumber}: Task Key '{$taskKey}' duplikat.");
            }

            $startDate = $this->dateValue($row['start_date'] ?? null, false, "Tasks baris {$rowNumber}: Mulai");
            $dueDate = $this->dateValue($row['due_date'] ?? null, false, "Tasks baris {$rowNumber}: Jatuh Tempo");
            if ($startDate !== null && $dueDate !== null && $dueDate->lt($startDate)) {
                throw new InvalidArgumentException("Tasks baris {$rowNumber}: Jatuh Tempo tidak boleh sebelum Mulai.");
            }

            $task = ProjectTask::create([
                'business_id' => $this->businessId,
                'project_id' => $project->id,
                'task_id' => $this->projectUtil->generateTaskId($this->businessId, $project->id),
                'subject' => $subject,
                'start_date' => $startDate ? $startDate->format('Y-m-d H:i:s') : null,
                'due_date' => $dueDate ? $dueDate->format('Y-m-d H:i:s') : null,
                'priority' => $this->enumValue($row['priority'] ?? null, ProjectTask::prioritiesDropdown(), 'low', "Tasks baris {$rowNumber}: Prioritas"),
                'description' => $this->nullableString($row['description'] ?? null),
                'created_by' => $this->importedBy,
                'status' => $this->enumValue($row['status'] ?? null, ProjectTask::taskStatuses(), 'not_started', "Tasks baris {$rowNumber}: Status"),
                'custom_field_1' => $this->nullableString($row['custom_field_1'] ?? null),
                'custom_field_2' => $this->nullableString($row['custom_field_2'] ?? null),
                'custom_field_3' => $this->nullableString($row['custom_field_3'] ?? null),
                'custom_field_4' => $this->nullableString($row['custom_field_4'] ?? null),
            ]);

            $task->members()->sync($this->resolveUserList($row['member_refs'] ?? null, "Tasks baris {$rowNumber}: Pekerja"));
            $task->tags()->sync($this->resolveNameList($row['tag_names'] ?? null, $this->tags, "Tasks baris {$rowNumber}: Tag"));
            $tasks[$normalizedKey] = $task;
            $this->rowOutcomes[] = [
                'sheet' => 'Tasks',
                'source_row' => (int) $rowNumber,
                'status' => 'created',
                'record_type' => 'task',
                'record_id' => (int) $task->id,
            ];
            $this->reportProgress('tasks', (int) $rowNumber, "Memproses Tasks baris {$rowNumber}.");
        }

        return $tasks;
    }

    private function createTimeLogs(Worksheet $sheet, Project $project, array $tasks): int
    {
        $count = 0;
        foreach ($this->readTable($sheet, ['task_key', 'worker_ref', 'work_date', 'shift', 'start_1', 'end_1', 'start_2', 'end_2', 'note', 'tag_names']) as $rowNumber => $row) {
            $task = null;
            $taskKey = $this->stringValue($row['task_key'] ?? null);
            if ($taskKey !== '') {
                $task = $tasks[strtolower($taskKey)] ?? null;
                if ($task === null) {
                    throw new InvalidArgumentException("Time Logs baris {$rowNumber}: Task Key '{$taskKey}' tidak ditemukan pada sheet Tasks.");
                }
            }

            $date = $this->dateValue($row['work_date'] ?? null, true, "Time Logs baris {$rowNumber}: Tanggal");
            $start1 = $this->dateTimeFromDateAndTime($date, $row['start_1'] ?? null, null, "Time Logs baris {$rowNumber}: Mulai 1");
            $end1 = $this->dateTimeFromDateAndTime($date, $row['end_1'] ?? null, $start1, "Time Logs baris {$rowNumber}: Selesai 1");

            $start2 = null;
            $end2 = null;
            $hasSecondStart = $this->hasValue($row['start_2'] ?? null);
            $hasSecondEnd = $this->hasValue($row['end_2'] ?? null);
            if ($hasSecondStart xor $hasSecondEnd) {
                throw new InvalidArgumentException("Time Logs baris {$rowNumber}: Mulai 2 dan Selesai 2 harus diisi berpasangan.");
            }
            if ($hasSecondStart) {
                $start2 = $this->dateTimeFromDateAndTime($date, $row['start_2'], $end1, "Time Logs baris {$rowNumber}: Mulai 2");
                $end2 = $this->dateTimeFromDateAndTime($date, $row['end_2'], $start2, "Time Logs baris {$rowNumber}: Selesai 2");
            }

            $shift = $this->stringValue($row['shift'] ?? null);
            if ($shift === '') {
                $shift = (int) $start1->format('H') >= 18 ? 'malam' : 'pagi';
            }
            $shift = $this->enumValue($shift, ['pagi' => 'pagi', 'malam' => 'malam'], null, "Time Logs baris {$rowNumber}: Shift");

            $userId = $this->resolveUserId($row['worker_ref'] ?? null, false, "Time Logs baris {$rowNumber}: Pekerja") ?: $this->importedBy;
            $timeLog = ProjectTimeLog::create([
                'project_id' => $project->id,
                'project_task_id' => $task ? $task->id : null,
                'user_id' => $userId,
                'shift' => $shift,
                'start_datetime' => $start1->format('Y-m-d H:i:s'),
                'end_datetime' => $end1->format('Y-m-d H:i:s'),
                'second_start_datetime' => $start2 ? $start2->format('Y-m-d H:i:s') : null,
                'second_end_datetime' => $end2 ? $end2->format('Y-m-d H:i:s') : null,
                'note' => $this->nullableString($row['note'] ?? null),
                'created_by' => $this->importedBy,
            ]);
            $timeLog->tags()->sync($this->resolveNameList($row['tag_names'] ?? null, $this->tags, "Time Logs baris {$rowNumber}: Tag"));
            $this->rowOutcomes[] = [
                'sheet' => 'Time Logs',
                'source_row' => (int) $rowNumber,
                'status' => 'created',
                'record_type' => 'time_log',
                'record_id' => (int) $timeLog->id,
            ];
            $this->reportProgress('time_logs', (int) $rowNumber, "Memproses Time Logs baris {$rowNumber}.");
            $count++;
        }

        return $count;
    }

    private function createInvoice(array $values, Worksheet $linesSheet, Project $project, array $tasks): ?ProjectTransaction
    {
        if (! $this->yesNoValue($values['generate_invoice'] ?? null, false)) {
            return null;
        }

        if (empty($project->settings['enable_invoice'])) {
            throw new InvalidArgumentException('Invoice: Aktifkan Invoice pada sheet Project harus bernilai ya.');
        }

        $customerId = $this->resolveReferenceId($values['customer_ref'] ?? null, 'C', $this->customers, false, 'Invoice: Customer');
        $customerId = $customerId ?: $project->contact_id;
        if (empty($customerId)) {
            throw new InvalidArgumentException('Invoice: Customer wajib diisi pada sheet Project atau Invoice.');
        }

        $locationId = $this->resolveReferenceId($values['location_ref'] ?? null, 'L', $this->locations, true, 'Invoice: Lokasi Bisnis');
        $status = $this->enumValue($values['status'] ?? null, ['draft' => 'draft', 'final' => 'final'], 'draft', 'Invoice: Status');
        $invoiceDate = $this->dateValue($values['invoice_date'] ?? null, false, 'Invoice: Tanggal Invoice') ?: Carbon::today();
        $schemeId = $this->resolveReferenceId($values['invoice_scheme_ref'] ?? null, 'S', $this->invoiceSchemes, false, 'Invoice: Skema Invoice');
        if (empty($schemeId)) {
            $defaultScheme = InvoiceScheme::getDefault($this->businessId);
            $schemeId = $defaultScheme ? $defaultScheme->id : null;
        }
        if ($status === 'final' && empty($schemeId)) {
            throw new InvalidArgumentException('Invoice: Skema invoice default tidak ditemukan untuk membuat invoice final.');
        }

        $lineInputs = [];
        $invoiceLineRows = [];
        $subtotal = 0;
        foreach ($this->readTable($linesSheet, ['task_key', 'item_name', 'quantity_source', 'manual_quantity', 'rate', 'tax_ref', 'description']) as $rowNumber => $row) {
            $task = null;
            $taskKey = $this->stringValue($row['task_key'] ?? null);
            if ($taskKey !== '') {
                $task = $tasks[strtolower($taskKey)] ?? null;
                if ($task === null) {
                    throw new InvalidArgumentException("Invoice Lines baris {$rowNumber}: Task Key '{$taskKey}' tidak ditemukan.");
                }
            }

            $itemName = $this->stringValue($row['item_name'] ?? null);
            if ($itemName === '' && $task !== null) {
                $itemName = $task->subject;
            }
            if ($itemName === '') {
                throw new InvalidArgumentException("Invoice Lines baris {$rowNumber}: Nama Item/Jasa atau Task Key wajib diisi.");
            }

            $source = $this->enumValue(
                $row['quantity_source'] ?? null,
                ['manual' => 'manual', 'task_hours' => 'task_hours', 'project_hours' => 'project_hours'],
                'manual',
                "Invoice Lines baris {$rowNumber}: Sumber Kuantitas"
            );
            if ($source === 'task_hours') {
                if ($task === null) {
                    throw new InvalidArgumentException("Invoice Lines baris {$rowNumber}: Task Key wajib untuk sumber task_hours.");
                }
                $quantity = $task->timeLogs()->get()->sum('total_seconds') / 3600;
            } elseif ($source === 'project_hours') {
                $quantity = $project->timeLogs()->get()->sum('total_seconds') / 3600;
            } else {
                $quantity = $this->numberValue($row['manual_quantity'] ?? null, "Invoice Lines baris {$rowNumber}: Qty Manual");
            }

            $rate = $this->numberValue($row['rate'] ?? null, "Invoice Lines baris {$rowNumber}: Tarif");
            if ($quantity <= 0 || $rate < 0) {
                throw new InvalidArgumentException("Invoice Lines baris {$rowNumber}: kuantitas harus lebih dari nol dan tarif tidak boleh negatif.");
            }

            $taxId = $this->resolveReferenceId($row['tax_ref'] ?? null, 'T', $this->taxes, false, "Invoice Lines baris {$rowNumber}: Pajak");
            $taxRate = $taxId ? (float) $this->taxes->get($taxId)->amount : 0;
            $total = round($quantity * $rate * (1 + ($taxRate / 100)), 4);
            $subtotal += $total;
            $lineInput = [
                'task' => $itemName,
                'description' => $this->nullableString($row['description'] ?? null),
                'rate' => $rate,
                'tax_rate_id' => $taxId,
                'quantity' => $quantity,
                'total' => $total,
            ];
            if ($task !== null) {
                $lineInput['project_task_id'] = $task->id;
            }
            $lineInputs[] = $lineInput;
            $invoiceLineRows[] = (int) $rowNumber;
        }

        if ($lineInputs === []) {
            throw new InvalidArgumentException('Invoice Lines: minimal satu baris harus diisi saat Generate Invoice = ya.');
        }

        $discountType = $this->enumValue(
            $values['discount_type'] ?? null,
            ['fixed' => 'fixed', 'percentage' => 'percentage'],
            'fixed',
            'Invoice: Tipe Diskon'
        );
        $discountAmount = $this->optionalNumberValue($values['discount_amount'] ?? null, 'Invoice: Nilai Diskon', 0);
        if ($discountType === 'percentage' && ($discountAmount < 0 || $discountAmount > 100)) {
            throw new InvalidArgumentException('Invoice: Diskon persentase harus antara 0 sampai 100.');
        }
        $discountValue = $discountType === 'percentage' ? ($subtotal * $discountAmount / 100) : $discountAmount;
        $finalTotal = round($subtotal - $discountValue, 4);
        if ($finalTotal < 0) {
            throw new InvalidArgumentException('Invoice: Diskon tidak boleh melebihi subtotal.');
        }

        $payTermNumber = $this->optionalNumberValue($values['pay_term_number'] ?? null, 'Invoice: Jangka Bayar', null);
        $payTermType = $payTermNumber === null ? null : $this->enumValue(
            $values['pay_term_type'] ?? null,
            ['days' => 'days', 'months' => 'months'],
            'days',
            'Invoice: Satuan Jangka Bayar'
        );

        $transaction = ProjectTransaction::create([
            'pjt_project_id' => $project->id,
            'pjt_title' => $this->stringValue($values['invoice_title'] ?? null) ?: $project->name,
            'contact_id' => $customerId,
            'pay_term_number' => $payTermNumber,
            'pay_term_type' => $payTermType,
            'status' => $status,
            'discount_type' => $discountType,
            'discount_amount' => $discountAmount,
            'staff_note' => $this->nullableString($values['staff_note'] ?? null),
            'additional_notes' => $this->nullableString($values['additional_notes'] ?? null),
            'location_id' => $locationId,
            'business_id' => $this->businessId,
            'created_by' => $this->importedBy,
            'transaction_date' => $invoiceDate->format('Y-m-d H:i:s'),
            'invoice_no' => $this->transactionUtil->getInvoiceNumber($this->businessId, $status, $locationId, $schemeId),
            'total_before_tax' => $subtotal,
            'final_total' => $finalTotal,
            'type' => 'sell',
            'sub_type' => 'project_invoice',
            'payment_status' => 'due',
        ]);
        $createdLines = $transaction->invoiceLines()->createMany($lineInputs);
        foreach ($createdLines as $index => $line) {
            $sourceRow = $invoiceLineRows[$index] ?? null;
            $this->rowOutcomes[] = [
                'sheet' => 'Invoice Lines',
                'source_row' => $sourceRow,
                'status' => 'created',
                'record_type' => 'invoice_line',
                'record_id' => (int) $line->id,
            ];
            $this->reportProgress('invoice_lines', (int) $sourceRow, 'Menyimpan Invoice Lines.');
        }

        return $transaction;
    }

    private function reportProgress(string $stage, int $sourceRow, string $message): void
    {
        $this->progressCurrent++;
        if ($this->progressListener !== null) {
            call_user_func($this->progressListener, [
                'stage' => $stage,
                'current' => $this->progressCurrent,
                'total' => $this->progressTotal,
                'source_row' => $sourceRow,
                'message' => $message,
            ]);
        }
    }

    private function readForm(Worksheet $sheet): array
    {
        $values = [];
        $lastRow = min($sheet->getHighestDataRow(), self::MAX_DATA_ROW);
        for ($row = 5; $row <= $lastRow; $row++) {
            $key = $this->stringValue($sheet->getCell("E{$row}")->getValue());
            if ($key !== '') {
                $values[$key] = $sheet->getCell("B{$row}")->getValue();
            }
        }

        return $values;
    }

    private function readTable(Worksheet $sheet, array $presenceKeys): array
    {
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $headers = [];
        for ($column = 1; $column <= $lastColumn; $column++) {
            $key = $this->stringValue($sheet->getCellByColumnAndRow($column, 3)->getValue());
            if ($key !== '') {
                $headers[$column] = $key;
            }
        }

        $rows = [];
        $lastRow = min($sheet->getHighestDataRow(), self::MAX_DATA_ROW);
        for ($rowNumber = 5; $rowNumber <= $lastRow; $rowNumber++) {
            $row = [];
            foreach ($headers as $column => $key) {
                $row[$key] = $sheet->getCellByColumnAndRow($column, $rowNumber)->getValue();
            }
            $hasInput = false;
            foreach ($presenceKeys as $key) {
                if ($this->hasValue($row[$key] ?? null)) {
                    $hasInput = true;
                    break;
                }
            }
            if ($hasInput) {
                $rows[$rowNumber] = $row;
            }
        }

        return $rows;
    }

    private function resolveUserId($value, bool $allowBlank, string $field): ?int
    {
        if (! $this->hasValue($value)) {
            if ($allowBlank) {
                return null;
            }

            return null;
        }

        $id = $this->extractReferenceId($value, 'U');
        if ($id !== null && $this->users->has($id)) {
            return (int) $id;
        }

        $email = strtolower($this->stringValue($value));
        if ($this->usersByEmail->has($email)) {
            return (int) $this->usersByEmail->get($email)->id;
        }

        throw new InvalidArgumentException("{$field}: pekerja/user tidak ditemukan pada bisnis ini.");
    }

    private function resolveUserList($value, string $field): array
    {
        $ids = [];
        foreach ($this->splitValues($value) as $item) {
            $ids[] = $this->resolveUserId($item, false, $field);
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function resolveReferenceId($value, string $prefix, Collection $items, bool $required, string $field): ?int
    {
        if (! $this->hasValue($value)) {
            if ($required) {
                throw new InvalidArgumentException("{$field} wajib diisi.");
            }

            return null;
        }

        $id = $this->extractReferenceId($value, $prefix);
        if ($id === null || ! $items->has($id)) {
            throw new InvalidArgumentException("{$field}: referensi tidak ditemukan pada bisnis ini.");
        }

        return (int) $id;
    }

    private function extractReferenceId($value, string $prefix): ?int
    {
        $value = $this->stringValue($value);
        if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)\b/i', $value, $matches)) {
            return (int) $matches[1];
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    private function resolveNameList($value, Collection $items, string $field): array
    {
        $ids = [];
        foreach ($this->splitValues($value) as $name) {
            $item = $items->first(function ($item) use ($name) {
                return strtolower(trim((string) $item->name)) === strtolower(trim($name));
            });
            if ($item === null) {
                throw new InvalidArgumentException("{$field}: '{$name}' tidak ditemukan pada referensi bisnis.");
            }
            $ids[] = (int) $item->id;
        }

        return array_values(array_unique($ids));
    }

    private function splitValues($value): array
    {
        $value = $this->stringValue($value);
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/[;\r\n]+/', $value))));
    }

    private function enumValue($value, array $allowed, $default, string $field)
    {
        $value = strtolower($this->stringValue($value));
        if ($value === '' && $default !== null) {
            return $default;
        }
        if (! array_key_exists($value, $allowed)) {
            throw new InvalidArgumentException("{$field}: nilai '{$value}' tidak valid.");
        }

        return $value;
    }

    private function yesNoValue($value, bool $default): bool
    {
        $value = strtolower($this->stringValue($value));
        if ($value === '') {
            return $default;
        }
        if (in_array($value, ['ya', 'yes', '1', 'true'], true)) {
            return true;
        }
        if (in_array($value, ['tidak', 'no', '0', 'false'], true)) {
            return false;
        }

        throw new InvalidArgumentException("Nilai ya/tidak '{$value}' tidak valid.");
    }

    private function dateValue($value, bool $required, string $field): ?Carbon
    {
        if (! $this->hasValue($value)) {
            if ($required) {
                throw new InvalidArgumentException("{$field} wajib diisi.");
            }

            return null;
        }

        if (is_numeric($value)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->startOfDay();
        }

        $value = trim((string) $value);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!' . $format, $value);
                if ($date !== false) {
                    return $date;
                }
            } catch (\Exception $e) {
                // Try the next documented format.
            }
        }

        throw new InvalidArgumentException("{$field}: gunakan format YYYY-MM-DD.");
    }

    private function dateTimeFromDateAndTime(Carbon $date, $value, ?Carbon $after, string $field): Carbon
    {
        [$hour, $minute] = $this->timeParts($value, $field);
        $dateTime = $date->copy()->startOfDay();
        if ($hour === 24) {
            $dateTime->addDay();
        } else {
            $dateTime->addHours($hour)->addMinutes($minute);
        }
        if ($after !== null && $dateTime->lte($after)) {
            $dateTime->addDay();
        }

        return $dateTime;
    }

    private function timeParts($value, string $field): array
    {
        if (! $this->hasValue($value)) {
            throw new InvalidArgumentException("{$field} wajib diisi.");
        }

        if (is_numeric($value)) {
            $numeric = (float) $value;
            if (abs($numeric - 1) < 0.000001) {
                return [24, 0];
            }
            $minutes = (int) round(($numeric - floor($numeric)) * 1440);
            return [intdiv($minutes, 60) % 24, $minutes % 60];
        }

        $value = str_replace('.', ':', trim((string) $value));
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches)) {
            throw new InvalidArgumentException("{$field}: gunakan format HH:MM.");
        }
        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
        if ($minute > 59 || $hour > 24 || ($hour === 24 && $minute !== 0)) {
            throw new InvalidArgumentException("{$field}: jam tidak valid.");
        }

        return [$hour, $minute];
    }

    private function numberValue($value, string $field): float
    {
        $number = $this->optionalNumberValue($value, $field, null);
        if ($number === null) {
            throw new InvalidArgumentException("{$field} wajib diisi.");
        }

        return $number;
    }

    private function optionalNumberValue($value, string $field, $default): ?float
    {
        if (! $this->hasValue($value)) {
            return $default;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $normalized = preg_replace('/[^0-9,.-]/', '', trim((string) $value));
        if (substr_count($normalized, ',') > 0 && substr_count($normalized, '.') > 0) {
            if (strrpos($normalized, ',') > strrpos($normalized, '.')) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif (substr_count($normalized, ',') === 1) {
            $normalized = str_replace(',', '.', $normalized);
        }

        if (! is_numeric($normalized)) {
            throw new InvalidArgumentException("{$field}: nilai angka tidak valid.");
        }

        return (float) $normalized;
    }

    private function stringValue($value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function nullableString($value): ?string
    {
        $value = $this->stringValue($value);

        return $value === '' ? null : $value;
    }

    private function hasValue($value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }
}
