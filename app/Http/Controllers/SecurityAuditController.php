<?php

namespace App\Http\Controllers;

use App\Services\SecurityAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SecurityAuditController extends Controller
{
    public function __construct(private SecurityAuditService $auditService)
    {
    }

    public function index(Request $request)
    {
        $this->authorizeAccess();
        $businessId = $this->businessId();
        $reports = $this->auditService->listReports($businessId);
        $report = null;
        $selectedId = (string) $request->query('report', $reports[0]['id'] ?? '');

        if ($selectedId !== '') {
            try {
                $report = $this->auditService->getReport($selectedId, $businessId);
            } catch (RuntimeException $exception) {
                abort(404, $exception->getMessage());
            }
        }

        return view('security-audit.index', [
            'reports' => $reports,
            'report' => $report,
            'businessId' => $businessId,
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $this->authorizeAccess();
        $request->validate([
            'report' => ['required', 'file', 'max:15360'],
        ]);

        try {
            $report = $this->auditService->importUpload(
                $request->file('report'),
                $this->businessId(),
                (int) $request->user()->id,
                $this->userName($request)
            );

            return response()->json([
                'success' => true,
                'message' => 'Laporan audit berhasil dimuat.',
                'report_id' => $report['id'],
                'redirect' => route('security-audit.index', ['report' => $report['id']]),
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function download(Request $request, string $report)
    {
        $this->authorizeAccess();

        try {
            $contents = $this->auditService->downloadContents($report, $this->businessId());
        } catch (RuntimeException $exception) {
            abort(404, $exception->getMessage());
        }

        return response($contents, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="security-audit-'.$report.'.json"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function cleanup(Request $request, string $report): JsonResponse
    {
        $this->authorizeAccess();
        $validated = $request->validate([
            'confirmation' => ['required', 'string', 'in:BERSIHKAN SEMUA'],
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($validated['password'], (string) $request->user()->password)) {
            return response()->json(['success' => false, 'message' => 'Password akun tidak sesuai.'], 422);
        }

        try {
            $result = $this->auditService->cleanup(
                $report,
                $this->businessId(),
                (int) $request->user()->id,
                $this->userName($request)
            );
            Log::warning('Security audit cleanup executed', [
                'report_id' => $report,
                'user_id' => $request->user()->id,
                'removed_count' => $result['removed_count'],
                'skipped_count' => $result['skipped_count'],
            ]);

            return response()->json([
                'success' => true,
                'message' => sprintf(
                    '%d file dipindahkan ke karantina; %d file dilewati.',
                    $result['removed_count'],
                    $result['skipped_count']
                ),
                'result' => $result,
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    private function authorizeAccess(): void
    {
        $user = auth()->user();
        $businessId = $this->businessId();
        $isAdmin = $user && $user->hasRole('Admin#'.$businessId);

        if (! $user || (! $isAdmin && ! $user->can('backup'))) {
            abort(403, 'Hanya admin yang dapat membuka audit keamanan.');
        }
    }

    private function businessId(): int
    {
        return (int) (session('business.id') ?: optional(auth()->user())->business_id);
    }

    private function userName(Request $request): string
    {
        $user = $request->user();
        $name = trim((string) ($user->first_name ?? '').' '.(string) ($user->last_name ?? ''));

        return $name !== '' ? $name : (string) ($user->username ?? ('User #'.$user->id));
    }
}
