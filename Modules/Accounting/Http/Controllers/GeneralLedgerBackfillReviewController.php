<?php

namespace Modules\Accounting\Http\Controllers;

use App\Utils\ModuleUtil;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Services\GeneralLedgerBackfillReviewService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class GeneralLedgerBackfillReviewController extends Controller
{
    private $moduleUtil;

    private $reviewService;

    public function __construct(ModuleUtil $moduleUtil, GeneralLedgerBackfillReviewService $reviewService)
    {
        $this->moduleUtil = $moduleUtil;
        $this->reviewService = $reviewService;
    }

    public function index(Request $request)
    {
        $businessId = $this->businessId($request);
        $this->authorizeBackfill($businessId, 'accounting.view_journal');

        return view('accounting::general_ledger.backfill_review', [
            'rowsUrl' => route('accounting.generalLedger.backfillReview.rows'),
            'optionsUrl' => route('accounting.generalLedger.backfillReview.options'),
            'locationMappingUrl' => route('accounting.generalLedger.backfillReview.locationMapping'),
            'mainAccountMappingUrl' => route('accounting.generalLedger.backfillReview.mainAccountMapping'),
            'vatMappingUrl' => route('accounting.generalLedger.backfillReview.vatMapping'),
            'retryUrl' => route('accounting.generalLedger.backfillReview.retry'),
            'batchUrlBase' => url('accounting/reports/general-ledger/backfill-review/retry-batches'),
            'canEdit' => auth()->user()->can('accounting.edit_journal'),
            'canRetry' => auth()->user()->can('accounting.add_journal'),
        ]);
    }

    public function rows(Request $request): JsonResponse
    {
        $businessId = $this->businessId($request);
        $this->authorizeBackfill($businessId, 'accounting.view_journal');
        $filters = $request->validate([
            'search' => 'nullable|string|max:191',
            'reason' => 'nullable|string|max:100',
            'source_type' => 'nullable|string|max:100',
            'status' => 'nullable|in:pending,retrying,resolved',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
            'location_id' => 'nullable|integer|min:1',
            'mapping_key' => 'nullable|string|max:64',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);
        if (! empty($filters['start_date']) && ! empty($filters['end_date']) && $filters['end_date'] < $filters['start_date']) {
            throw ValidationException::withMessages(['end_date' => 'Tanggal akhir harus sama atau setelah tanggal mulai.']);
        }

        return response()->json($this->reviewService->listItems($businessId, $filters));
    }

    public function options(Request $request): JsonResponse
    {
        $businessId = $this->businessId($request);
        $this->authorizeBackfill($businessId, 'accounting.view_journal');

        return response()->json($this->reviewService->mappingOptions($businessId));
    }

    public function saveLocationMapping(Request $request): JsonResponse
    {
        $businessId = $this->businessId($request);
        $this->authorizeBackfill($businessId, 'accounting.edit_journal');
        $data = $request->validate([
            'location_id' => 'required|integer|min:1',
            'mapping_key' => 'required|string|max:64',
            'payment_account_id' => 'nullable|integer|min:1',
            'deposit_to_id' => 'nullable|integer|min:1',
        ]);
        $count = $this->reviewService->saveLocationMapping(
            $businessId,
            (int) request()->session()->get('user.id'),
            $data
        );

        return response()->json([
            'success' => true,
            'matched_count' => $count,
            'scope' => [
                'type' => 'location',
                'location_id' => (int) $data['location_id'],
                'mapping_key' => (string) $data['mapping_key'],
            ],
            'message' => $count.' item menunggu retry untuk mapping lokasi ini.',
        ]);
    }

    public function saveVatMapping(Request $request): JsonResponse
    {
        $businessId = $this->businessId($request);
        $this->authorizeBackfill($businessId, 'accounting.edit_journal');
        $data = $request->validate([
            'direction' => 'required|in:input,output',
            'accounting_account_id' => 'required|integer|min:1',
        ]);
        $count = $this->reviewService->saveVatMapping(
            $businessId,
            (int) request()->session()->get('user.id'),
            $data['direction'],
            (int) $data['accounting_account_id']
        );

        return response()->json([
            'success' => true,
            'matched_count' => $count,
            'scope' => ['type' => 'vat', 'direction' => $data['direction']],
            'message' => $count.' item dengan selisih mapping VAT cocok untuk dicoba ulang.',
        ]);
    }

    public function saveMainAccountMapping(Request $request): JsonResponse
    {
        $businessId = $this->businessId($request);
        $this->authorizeBackfill($businessId, 'accounting.edit_journal');
        $data = $request->validate([
            'main_account_id' => 'required|integer|min:1',
            'accounting_account_id' => 'required|integer|min:1',
        ]);
        $count = $this->reviewService->saveMainAccountMapping(
            $businessId,
            (int) request()->session()->get('user.id'),
            (int) $data['main_account_id'],
            (int) $data['accounting_account_id']
        );

        return response()->json([
            'success' => true,
            'matched_count' => $count,
            'scope' => ['type' => 'main_account', 'main_account_id' => (int) $data['main_account_id']],
            'message' => $count.' item memakai akun sumber ini dan cocok untuk dicoba ulang.',
        ]);
    }

    public function retry(Request $request): JsonResponse
    {
        $businessId = $this->businessId($request);
        $this->authorizeBackfill($businessId, 'accounting.add_journal');
        $data = $request->validate([
            'type' => 'required|in:location,main_account,vat',
            'location_id' => 'required_if:type,location|nullable|integer|min:1',
            'mapping_key' => 'required_if:type,location|nullable|string|max:64',
            'main_account_id' => 'required_if:type,main_account|nullable|integer|min:1',
            'direction' => 'required_if:type,vat|nullable|in:input,output',
        ]);
        $batch = $this->reviewService->enqueueRetry($businessId, (int) request()->session()->get('user.id'), $data);

        return response()->json(['success' => true] + $batch, $batch['total_items'] > 0 ? 202 : 200);
    }

    public function batchStatus(Request $request, int $batchId): JsonResponse
    {
        $businessId = $this->businessId($request);
        $this->authorizeBackfill($businessId, 'accounting.view_journal');
        $batch = $this->reviewService->retryBatchStatus($businessId, $batchId);
        if ($batch === null) {
            abort(404);
        }

        return response()->json($batch);
    }

    private function businessId(Request $request): int
    {
        $businessId = (int) $request->session()->get('user.business_id', 0);
        abort_if($businessId < 1, 403, 'Business aktif tidak tersedia.');

        return $businessId;
    }

    private function authorizeBackfill(int $businessId, string $permission): void
    {
        $user = auth()->user();
        $isSuperadmin = $user && $user->can('superadmin');
        if (! $user || (! $isSuperadmin && ! $this->moduleUtil->hasThePermissionInSubscription($businessId, 'accounting_module'))
            || ! $user->can($permission)) {
            throw new HttpException(403, 'Unauthorized action.');
        }
    }
}
