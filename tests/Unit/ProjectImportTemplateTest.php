<?php

namespace Tests\Unit;

use Modules\Project\Services\ProjectImportTemplate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

class ProjectImportTemplateTest extends TestCase
{
    public function test_it_builds_a_round_trip_safe_relational_project_template(): void
    {
        $template = new ProjectImportTemplate();
        $workbook = $template->build([
            'customers' => ['C1 | Example Customer'],
            'users' => ['U2 | Example Worker | worker@example.test'],
            'locations' => ['L3 | Main Location'],
            'taxes' => [['label' => 'T4 | VAT (11%)', 'rate' => 11]],
            'categories' => ['Operations'],
            'tags' => ['Loader'],
            'invoice_schemes' => ['S5 | Default'],
            'current_user' => 'U2 | Example Worker | worker@example.test',
            'default_location' => 'L3 | Main Location',
            'default_invoice_scheme' => 'S5 | Default',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'project-template-') . '.xlsx';

        try {
            (new Xlsx($workbook))->save($path);
            $roundTrip = IOFactory::load($path);

            $this->assertSame(
                ['Petunjuk', 'Project', 'Tasks', 'Time Logs', 'Invoice', 'Invoice Lines', 'Referensi'],
                $roundTrip->getSheetNames()
            );
            $this->assertSame(ProjectImportTemplate::VERSION, (string) $roundTrip->getSheetByName('Referensi')->getCell('T2')->getValue());
            $this->assertSame('project_name', $roundTrip->getSheetByName('Project')->getCell('E5')->getValue());
            $this->assertFalse($roundTrip->getSheetByName('Tasks')->getRowDimension(3)->getVisible());
            $this->assertSame('=Shifts', $roundTrip->getSheetByName('Time Logs')->getCell('D5')->getDataValidation()->getFormula1());
            $this->assertStringContainsString("COUNTA(C5,E5,F5)<3", $roundTrip->getSheetByName('Time Logs')->getCell('J5')->getValue());
            $this->assertStringContainsString("SUMIF('Time Logs'", $roundTrip->getSheetByName('Invoice Lines')->getCell('F5')->getValue());
            $this->assertSame('FFF7D6', $roundTrip->getSheetByName('Project')->getStyle('B5')->getFill()->getStartColor()->getRGB());
            $this->assertStringContainsString('Cara pengisian', $roundTrip->getSheetByName('Project')->getComment('B5')->getText()->getPlainText());
            $this->assertStringContainsString('TASK-LOADER-01', $roundTrip->getSheetByName('Tasks')->getComment('A4')->getText()->getPlainText());
            $this->assertStringContainsString('HH:MM', $roundTrip->getSheetByName('Time Logs')->getComment('E4')->getText()->getPlainText());
            $this->assertStringContainsString('task_hours', $roundTrip->getSheetByName('Invoice Lines')->getComment('D4')->getText()->getPlainText());
            $this->assertStringContainsString('tidak perlu diubah', $roundTrip->getSheetByName('Invoice')->getComment('B18')->getText()->getPlainText());
            $this->assertStringContainsString('jangan mengubah daftar', $roundTrip->getSheetByName('Referensi')->getComment('A1')->getText()->getPlainText());
            $this->assertArrayHasKey('USERS', $roundTrip->getNamedRanges());
            $this->assertArrayHasKey('CUSTOMERS', $roundTrip->getNamedRanges());
            $this->assertArrayHasKey('TAXES', $roundTrip->getNamedRanges());
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
