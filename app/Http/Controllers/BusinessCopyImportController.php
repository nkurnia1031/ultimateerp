<?php

namespace App\Http\Controllers;

use App\Services\BusinessCopyImportBatchService;
use App\Services\UserImportBatchService;
use Illuminate\Http\Request;

class BusinessCopyImportController extends Controller
{
    public function history(Request $request, string $type, BusinessCopyImportBatchService $service)
    {
        $businessId = (int) $request->session()->get('user.business_id');
        $this->authorizeImport($type);

        return response()->json($service->historyForType($request, $type, $businessId));
    }

    public function detail(Request $request, string $type, int $batch, BusinessCopyImportBatchService $service)
    {
        $this->authorizeImport($type);
        $detail = $service->detail($type, $batch, (int) $request->session()->get('user.business_id'));
        $detail['results']['row_outcomes'] = collect($detail['results']['row_outcomes'] ?? [])->map(function (array $row) use ($type) {
            if (empty($row['target_id'])) {
                return $row;
            }
            $url = match ($type) {
                'contacts' => action([ContactController::class, 'show'], $row['target_id']),
                'expense_categories' => action([ExpenseCategoryController::class, 'edit'], $row['target_id']),
                'users' => action([ManageUserController::class, 'show'], $row['target_id']),
            };
            $row['record_url'] = $url;

            return $row;
        })->values();

        return response()->json($detail);
    }

    public function retry(Request $request, string $type, int $batch, BusinessCopyImportBatchService $service, UserImportBatchService $batches)
    {
        $this->authorizeImport($type);
        $record = $batches->findForBusiness($batch, (int) $request->session()->get('user.business_id'), $service->importerKey($type));
        $service->retry($record);

        return response()->json(['success' => true, 'status' => 'queued_preview']);
    }

    public function revert(Request $request, string $type, int $batch, BusinessCopyImportBatchService $service, UserImportBatchService $batches)
    {
        $this->authorizeImport($type);
        $record = $batches->findForBusiness($batch, (int) $request->session()->get('user.business_id'), $service->importerKey($type));
        $service->revert($record, $type);

        return response()->json(['success' => true, 'status' => 'reverted']);
    }

    private function authorizeImport(string $type): void
    {
        if ($type === 'users') {
            abort_unless(auth()->user()->can('user.create')
                && (auth()->user()->can('admin') || auth()->user()->can('superadmin')), 403,
                'Hanya admin dengan izin membuat user yang dapat melihat riwayat salin user.');

            return;
        }
        if ($type === 'expense_categories') {
            abort_unless((auth()->user()->can('expense.add') || auth()->user()->can('expense.edit'))
                && (auth()->user()->can('admin') || auth()->user()->can('superadmin')), 403,
                'Anda tidak memiliki izin melihat riwayat salin kategori biaya.');

            return;
        }
        abort_unless(auth()->user()->can('supplier.create') || auth()->user()->can('customer.create'), 403,
            'Anda tidak memiliki izin melihat riwayat salin kontak.');
    }
}
