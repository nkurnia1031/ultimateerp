<?php

namespace Tests\Unit;

use App\Category;
use App\Contact;
use App\Tag;
use App\TaxRate;
use App\User;
use Illuminate\Support\Collection;
use Modules\Project\Entities\InvoiceLine;
use Modules\Project\Entities\Project;
use Modules\Project\Entities\ProjectTask;
use Modules\Project\Entities\ProjectTimeLog;
use Modules\Project\Entities\ProjectTransaction;
use Modules\Project\Services\ProjectImportTemplate;
use Modules\Project\Services\ProjectWorkbookExporter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

class ProjectWorkbookExporterTest extends TestCase
{
    public function test_it_keeps_invoice_optional_when_project_has_no_invoice(): void
    {
        $project = (new Project())->forceFill([
            'id' => 1,
            'name' => 'Project Tanpa Invoice',
            'status' => 'not_started',
            'settings' => [],
        ]);
        $project->setRelation('members', new Collection());
        $project->setRelation('categories', new Collection());
        $project->setRelation('tasks', new Collection());
        $project->setRelation('timeLogs', new Collection());

        $workbook = (new ProjectWorkbookExporter(new ProjectImportTemplate()))->build($project, null, []);

        $this->assertSame('Project Tanpa Invoice', $workbook->getSheetByName('Project')->getCell('B5')->getValue());
        $this->assertSame('tidak', $workbook->getSheetByName('Invoice')->getCell('B5')->getValue());
        $this->assertSame("=IF(C5=\"\",\"\",ROW()-4)", $workbook->getSheetByName('Invoice Lines')->getCell('A5')->getValue());
        $this->assertNull($workbook->getSheetByName('Invoice Lines')->getCell('C5')->getValue());
    }

    public function test_it_exports_project_data_in_the_import_template_format(): void
    {
        $worker = (new User())->forceFill([
            'id' => 21,
            'first_name' => 'Syahrul',
            'last_name' => '',
            'surname' => '',
            'email' => 'syahrul@example.test',
        ]);
        $customer = (new Contact())->forceFill(['id' => 11, 'name' => 'PT Contoh Customer']);
        $category = (new Category())->forceFill(['id' => 31, 'name' => 'Operasional']);
        $tag = (new Tag())->forceFill(['id' => 41, 'name' => 'Wheel Loader']);

        $task = (new ProjectTask())->forceFill([
            'id' => 51,
            'task_id' => 'TASK-LOADER-01',
            'subject' => 'Operasional Wheel Loader',
            'description' => 'Pemindahan material',
            'start_date' => '2026-06-04 00:00:00',
            'due_date' => '2026-06-05 00:00:00',
            'priority' => 'high',
            'status' => 'in_progress',
            'custom_field_1' => 'Unit WL-01',
        ]);
        $task->setRelation('members', new Collection([$worker]));
        $task->setRelation('tags', new Collection([$tag]));

        $timeLog = (new ProjectTimeLog())->forceFill([
            'id' => 61,
            'project_task_id' => 51,
            'user_id' => 21,
            'shift' => 'malam',
            'start_datetime' => '2026-06-04 21:00:00',
            'end_datetime' => '2026-06-05 00:00:00',
            'second_start_datetime' => '2026-06-05 01:00:00',
            'second_end_datetime' => '2026-06-05 07:00:00',
            'note' => 'Istirahat 00:00-01:00',
        ]);
        $timeLog->setRelation('user', $worker);
        $timeLog->setRelation('tags', new Collection([$tag]));

        $project = (new Project())->forceFill([
            'id' => 71,
            'name' => 'Project Bongkar Muat',
            'contact_id' => 11,
            'status' => 'in_progress',
            'lead_id' => 21,
            'start_date' => '2026-06-04 00:00:00',
            'end_date' => '2026-06-05 00:00:00',
            'description' => 'Pekerjaan bongkar muat kapal',
            'settings' => [
                'enable_timelog' => true,
                'enable_invoice' => true,
                'enable_notes_documents' => true,
                'members_crud_task' => false,
                'members_crud_note' => false,
                'members_crud_timelog' => true,
                'task_view' => 'list_view',
                'task_id_prefix' => 'TASK-',
            ],
        ]);
        $project->setRelation('customer', $customer);
        $project->setRelation('lead', $worker);
        $project->setRelation('members', new Collection([$worker]));
        $project->setRelation('categories', new Collection([$category]));
        $project->setRelation('tasks', new Collection([$task]));
        $project->setRelation('timeLogs', new Collection([$timeLog]));

        $tax = (new TaxRate())->forceFill(['id' => 81, 'name' => 'PPN', 'amount' => 11]);
        $line = (new InvoiceLine())->forceFill([
            'id' => 91,
            'project_task_id' => 51,
            'task' => 'Operasional Wheel Loader',
            'description' => 'Tarif per jam',
            'quantity' => 9,
            'rate' => 350000,
            'tax_rate_id' => 81,
            'total' => 3496500,
        ]);
        $line->setRelation('tax', $tax);
        $line->setRelation('projectTask', $task);

        $invoice = (new ProjectTransaction())->forceFill([
            'id' => 101,
            'pjt_title' => 'Invoice Bongkar Muat',
            'contact_id' => 11,
            'location_id' => 12,
            'transaction_date' => '2026-06-05 00:00:00',
            'status' => 'draft',
            'pay_term_number' => 14,
            'pay_term_type' => 'days',
            'discount_type' => 'fixed',
            'discount_amount' => 0,
            'staff_note' => 'Pembayaran 14 hari',
            'additional_notes' => 'Terima kasih',
        ]);
        $invoice->setRelation('invoiceLines', new Collection([$line]));

        $references = [
            'customers' => ['C11 | PT Contoh Customer'],
            'users' => ['U21 | Syahrul | syahrul@example.test'],
            'locations' => ['L12 | Kantor Utama'],
            'taxes' => [['label' => 'T81 | PPN (11%)', 'rate' => 11]],
            'categories' => ['Operasional'],
            'tags' => ['Wheel Loader'],
            'invoice_schemes' => ['S13 | Default'],
            'current_user' => 'U21 | Syahrul | syahrul@example.test',
            'default_location' => 'L12 | Kantor Utama',
            'default_invoice_scheme' => 'S13 | Default',
        ];

        $exporter = new ProjectWorkbookExporter(new ProjectImportTemplate());
        $workbook = $exporter->build($project, $invoice, $references);
        $path = tempnam(sys_get_temp_dir(), 'project-export-') . '.xlsx';

        try {
            (new Xlsx($workbook))->save($path);
            $roundTrip = IOFactory::load($path);
            $this->assertWorkbookHasNoFormulaErrors($roundTrip);

            $this->assertSame(
                ['Petunjuk', 'Project', 'Tasks', 'Time Logs', 'Invoice', 'Invoice Lines', 'Referensi'],
                $roundTrip->getSheetNames()
            );
            $this->assertSame('Project Bongkar Muat', $roundTrip->getSheetByName('Project')->getCell('B5')->getValue());
            $this->assertSame('C11 | PT Contoh Customer', $roundTrip->getSheetByName('Project')->getCell('B6')->getValue());
            $this->assertSame('TASK-LOADER-01', $roundTrip->getSheetByName('Tasks')->getCell('A5')->getValue());
            $this->assertSame('U21 | Syahrul | syahrul@example.test', $roundTrip->getSheetByName('Time Logs')->getCell('B5')->getValue());
            $this->assertSame('malam', $roundTrip->getSheetByName('Time Logs')->getCell('D5')->getValue());
            $this->assertStringContainsString('COUNTA(C5,E5,F5)<3', $roundTrip->getSheetByName('Time Logs')->getCell('J5')->getValue());
            $this->assertEqualsWithDelta(9, $roundTrip->getSheetByName('Time Logs')->getCell('J5')->getCalculatedValue(), 0.0001);
            $this->assertSame('ya', $roundTrip->getSheetByName('Invoice')->getCell('B5')->getValue());
            $this->assertSame('Invoice Bongkar Muat', $roundTrip->getSheetByName('Invoice')->getCell('B6')->getValue());
            $this->assertSame('TASK-LOADER-01', $roundTrip->getSheetByName('Invoice Lines')->getCell('B5')->getValue());
            $this->assertSame('manual', $roundTrip->getSheetByName('Invoice Lines')->getCell('D5')->getValue());
            $this->assertSame(9.0, $roundTrip->getSheetByName('Invoice Lines')->getCell('E5')->getValue());
            $this->assertSame('T81 | PPN (11%)', $roundTrip->getSheetByName('Invoice Lines')->getCell('H5')->getValue());
            $this->assertEqualsWithDelta(3496500, $roundTrip->getSheetByName('Invoice Lines')->getCell('I5')->getCalculatedValue(), 0.0001);
            $this->assertStringContainsString('task_hours', $roundTrip->getSheetByName('Invoice Lines')->getComment('D4')->getText()->getPlainText());
            $this->assertSame(ProjectImportTemplate::VERSION, (string) $roundTrip->getSheetByName('Referensi')->getCell('T2')->getValue());
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function assertWorkbookHasNoFormulaErrors($workbook): void
    {
        $errors = [];
        foreach ($workbook->getAllSheets() as $sheet) {
            foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
                $cell = $sheet->getCell($coordinate);
                if ($cell->getDataType() !== 'f') {
                    continue;
                }

                try {
                    $value = $cell->getCalculatedValue();
                    if (is_string($value) && preg_match('/^#(?:DIV\/0!|N\/A|NAME\?|NULL!|NUM!|REF!|VALUE!)/', $value)) {
                        $errors[] = $sheet->getTitle() . '!' . $coordinate . '=' . $value;
                    }
                } catch (\Throwable $exception) {
                    $errors[] = $sheet->getTitle() . '!' . $coordinate . ': ' . $exception->getMessage();
                }
            }
        }

        $this->assertSame([], $errors);
    }
}
