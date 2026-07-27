<?php

namespace Modules\Project\Http\Controllers;

use App\BusinessLocation;
use App\Category;
use App\Contact;
use App\InvoiceScheme;
use App\Tag;
use App\TaxRate;
use App\User;
use App\Utils\ModuleUtil;
use App\Utils\Util;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Project\Entities\Project;
use Modules\Project\Entities\ProjectTransaction;
use Modules\Project\Services\ProjectImportTemplate;
use Modules\Project\Services\ProjectWorkbookExporter;
use Modules\Project\Services\ProjectWorkbookImporter;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class ProjectImportController extends Controller
{
    protected $moduleUtil;

    protected $template;

    protected $importer;

    protected $exporter;

    protected $commonUtil;

    public function __construct(
        ModuleUtil $moduleUtil,
        ProjectImportTemplate $template,
        ProjectWorkbookImporter $importer,
        ProjectWorkbookExporter $exporter,
        Util $commonUtil
    )
    {
        $this->moduleUtil = $moduleUtil;
        $this->template = $template;
        $this->importer = $importer;
        $this->exporter = $exporter;
        $this->commonUtil = $commonUtil;
    }

    public function index(Request $request)
    {
        $this->authorizeImport($request);

        return view('project::project_import.index');
    }

    public function downloadTemplate(Request $request)
    {
        $businessId = $this->authorizeImport($request);
        $workbook = $this->template->build($this->templateReferences($businessId));
        $workbook->getProperties()
            ->setCreator(config('app.name'))
            ->setTitle('Template Import Project Terpadu')
            ->setDescription('Template Project, Task, Time Log dua sesi, dan Project Invoice.');

        return response()->streamDownload(function () use ($workbook) {
            $writer = new Xlsx($workbook);
            $writer->setPreCalculateFormulas(false);
            $writer->save('php://output');
            $workbook->disconnectWorksheets();
        }, 'template-import-project.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
        ]);
    }

    public function store(Request $request)
    {
        $businessId = $this->authorizeImport($request);
        $request->validate([
            'project_file' => 'required|file|mimes:xlsx,xls|max:5120',
            'confirm_import' => 'accepted',
        ]);

        try {
            $result = $this->importer->import(
                $request->file('project_file')->getRealPath(),
                $businessId,
                (int) $request->user()->id
            );

            $message = __('project::lang.project_import_success', [
                'tasks' => $result['tasks'],
                'time_logs' => $result['time_logs'],
                'invoice' => $result['invoice'] ? 1 : 0,
            ]);

            return redirect()->action(
                [ProjectController::class, 'show'],
                [$result['project']->id]
            )->with('status', ['success' => true, 'msg' => $message]);
        } catch (InvalidArgumentException $exception) {
            return redirect()->back()
                ->withInput()
                ->with('status', ['success' => false, 'msg' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            \Log::emergency('File:'.$exception->getFile().' Line:'.$exception->getLine().' Message:'.$exception->getMessage());

            return redirect()->back()
                ->withInput()
                ->with('status', ['success' => false, 'msg' => __('messages.something_went_wrong')]);
        }
    }

    public function export(Request $request, $id)
    {
        $businessId = (int) $request->session()->get('user.business_id');
        $this->authorizeProjectModule($businessId);

        $query = Project::with([
            'customer',
            'lead',
            'members',
            'categories',
            'tasks' => function ($query) {
                $query->orderBy('id');
            },
            'tasks.members',
            'tasks.tags',
            'timeLogs' => function ($query) {
                $query->orderBy('start_datetime')->orderBy('id');
            },
            'timeLogs.user',
            'timeLogs.tags',
        ])->where('business_id', $businessId);

        if (! $this->commonUtil->is_admin($request->user(), $businessId)) {
            $query->whereHas('members', function ($query) use ($request) {
                $query->where('user_id', $request->user()->id);
            });
        }

        $project = $query->findOrFail($id);
        $invoice = ProjectTransaction::with([
            'invoiceLines' => function ($query) {
                $query->orderBy('id');
            },
            'invoiceLines.tax',
            'invoiceLines.projectTask',
        ])
            ->where('business_id', $businessId)
            ->where('pjt_project_id', $project->id)
            ->latest('transaction_date')
            ->latest('id')
            ->first();

        try {
            $workbook = $this->exporter->build($project, $invoice, $this->templateReferences($businessId));
        } catch (InvalidArgumentException $exception) {
            return redirect()->back()
                ->with('status', ['success' => false, 'msg' => $exception->getMessage()]);
        }

        $workbook->getProperties()
            ->setCreator(config('app.name'))
            ->setTitle('Export Project - ' . $project->name)
            ->setDescription('Export Project dalam format yang kompatibel dengan template import Project.');

        $fileName = 'project-' . (Str::slug($project->name) ?: $project->id) . '.xlsx';

        return response()->streamDownload(function () use ($workbook) {
            $writer = new Xlsx($workbook);
            $writer->setPreCalculateFormulas(false);
            $writer->save('php://output');
            $workbook->disconnectWorksheets();
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
        ]);
    }

    private function authorizeImport(Request $request): int
    {
        $businessId = (int) $request->session()->get('user.business_id');
        $allowed = auth()->user()->can('superadmin')
            || ($this->moduleUtil->hasThePermissionInSubscription($businessId, 'project_module')
                && auth()->user()->can('project.create_project'));

        if (! $allowed) {
            abort(403, 'Unauthorized action.');
        }

        return $businessId;
    }

    private function authorizeProjectModule(int $businessId): void
    {
        $allowed = auth()->user()->can('superadmin')
            || $this->moduleUtil->hasThePermissionInSubscription($businessId, 'project_module');

        if (! $allowed) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function templateReferences(int $businessId): array
    {
        $customers = Contact::customersDropdown($businessId, false)->map(function ($name, $id) {
            return $this->referenceLabel('C', $id, $name);
        })->values()->all();

        $users = User::where('business_id', $businessId)
            ->user()
            ->where('is_cmmsn_agnt', 0)
            ->get()
            ->mapWithKeys(function ($user) {
                $name = trim($user->user_full_name);
                $suffix = ! empty($user->email) ? $name . ' | ' . $user->email : $name;

                return [$user->id => $this->referenceLabel('U', $user->id, $suffix)];
            });

        $locations = BusinessLocation::forDropdown($businessId)->map(function ($name, $id) {
            return $this->referenceLabel('L', $id, $name);
        });

        $taxes = TaxRate::where('business_id', $businessId)
            ->excludeForTaxGroup()
            ->orderBy('name')
            ->get()
            ->map(function ($tax) {
                return [
                    'label' => $this->referenceLabel('T', $tax->id, $tax->name . ' (' . (float) $tax->amount . '%)'),
                    'rate' => (float) $tax->amount,
                ];
            })->values()->all();

        $categories = Category::where('business_id', $businessId)
            ->where('category_type', 'project')
            ->where('parent_id', 0)
            ->orderBy('name')
            ->pluck('name')
            ->values()
            ->all();
        $tags = Tag::where('business_id', $businessId)->orderBy('name')->pluck('name')->values()->all();
        $invoiceSchemes = InvoiceScheme::forDropdown($businessId)->map(function ($name, $id) {
            return $this->referenceLabel('S', $id, $name);
        });
        $defaultScheme = InvoiceScheme::getDefault($businessId);

        return [
            'customers' => $customers,
            'users' => $users->values()->all(),
            'locations' => $locations->values()->all(),
            'taxes' => $taxes,
            'categories' => $categories,
            'tags' => $tags,
            'invoice_schemes' => $invoiceSchemes->values()->all(),
            'current_user' => $users->get(auth()->id(), ''),
            'default_location' => $locations->first() ?: '',
            'default_invoice_scheme' => $defaultScheme ? $invoiceSchemes->get($defaultScheme->id, '') : '',
        ];
    }

    private function referenceLabel(string $prefix, $id, string $name): string
    {
        return $prefix . $id . ' | ' . trim($name);
    }
}
