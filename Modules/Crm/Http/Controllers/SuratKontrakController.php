<?php

namespace Modules\Crm\Http\Controllers;

use App\Category;
use App\Contact;
use App\User;
use App\Utils\ModuleUtil;
use App\Utils\Util;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Crm\Entities\SuratKontrak;
use Modules\Crm\Utils\CrmUtil;
use Excel;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Support\Facades\DB;

use PhpOffice\PhpSpreadsheet\Shared\Date;

class SuratKontrakController extends Controller
{
    protected $commonUtil;

    protected $moduleUtil;

    protected $crmUtil;

    /**
     * Constructor
     *
     * @param  Util  $commonUtil
     * @return void
     */
    public function __construct(Util $commonUtil, ModuleUtil $moduleUtil, CrmUtil $crmUtil)
    {
        $this->commonUtil = $commonUtil;
        $this->moduleUtil = $moduleUtil;
        $this->crmUtil = $crmUtil;
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        $business_id = request()->session()->get('user.business_id');
        $can_access_all_leads = auth()->user()->can('crm.access_all_leads');
        $can_access_own_leads = auth()->user()->can('crm.access_own_leads');

        if (! (auth()->user()->can('superadmin') || $this->moduleUtil->hasThePermissionInSubscription($business_id, 'crm_module')) || ! ($can_access_all_leads || $can_access_own_leads)) {
            abort(403, 'Unauthorized action.');
        }

        $life_stages = Category::forDropdown($business_id, 'life_stage');



        if (request()->ajax()) {

            $surat_kontrak = SuratKontrak::leftJoin('contacts', 'surat_kontrak.contact_id', '=', 'contacts.id')
                ->select(
                    'surat_kontrak.*',
                    'contacts.supplier_business_name as contact_name'
                );

            return Datatables::of($surat_kontrak)

                ->editColumn('mulai_kerja', '
                    @if(!empty($mulai_kerja))
                        {{ @format_date($mulai_kerja) }}
                    @endif
                ')

                ->editColumn('selesai', '
                    @if(!empty($selesai))
                        {{ @format_date($selesai) }}
                    @endif
                ')

                ->addColumn('action', function ($row) {

                    $html = '<div class="btn-group">
                                <button class="btn btn-info dropdown-toggle btn-xs" type="button" data-toggle="dropdown">
                                    ' . __('messages.action') . '
                                    <span class="caret"></span>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-left" role="menu">
            
                                    <li>
                                        <a class="btn-add-lead" href="javascript:;"  data-href="' . action([\Modules\Crm\Http\Controllers\SuratKontrakController::class, 'show'], $row->id) . '">
                                            <i class="fa fa-eye"></i> ' . __('messages.view') . '
                                        </a>
                                    </li>
            
                                    <li>
                                        <a class="btn-add-lead" href="javascript:;"  data-href="' . action([\Modules\Crm\Http\Controllers\SuratKontrakController::class, 'edit'], $row->id) . '">
                                            <i class="fa fa-edit"></i> ' . __('messages.edit') . '
                                        </a>
                                    </li>
            
                                    <li>
                                        <a  href="javascript:;"  data-href="' . action([\Modules\Crm\Http\Controllers\SuratKontrakController::class, 'destroy'], $row->id) . '" 
                                           class="delete_surat_kontrak">
                                            <i class="fa fa-trash"></i> ' . __('messages.delete') . '
                                        </a>
                                    </li>
            
                                </ul>
                            </div>';

                    return $html;
                })

                ->rawColumns(['action', 'mulai_kerja', 'selesai'])
                ->make(true);
        }

        $sources = Category::forDropdown($business_id, 'source');

        $users = User::forDropdown($business_id, false, false, false, true);
        $listBatch=$this->getImportBatchList();

        return view('crm::surat_kontrak.index')
            ->with(compact('sources', 'life_stages', 'users', 'listBatch'));
    }
    public function getImportBatchList()
    {
        $business_id = request()->session()->get('user.business_id');

        $batches = SuratKontrak::where('business_id', $business_id)
            ->whereNotNull('import_batch')
            ->select(
                'import_batch',
                DB::raw('MIN(created_at) as created_at'),
                DB::raw('GROUP_CONCAT(nomor SEPARATOR ", ") as nomor_list')
            )
            ->groupBy('import_batch')
            ->orderBy('import_batch', 'desc')
            ->get();

        return $batches;
    }
    public function destroyBatch($batch)
    {
        if (!request()->ajax()) {
            abort(404);
        }

        $business_id = request()->session()->get('user.business_id');

        if (!(auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'crm_module'))) {
            abort(403, 'Unauthorized action.');
        }

        try {

            SuratKontrak::where('business_id', $business_id)
                ->where('import_batch', $batch)
                ->delete();

            $output = [
                'success' => true,
                'msg' => 'Batch #' . $batch . ' berhasil dihapus'
            ];
        } catch (\Exception $e) {

            \Log::emergency('File:' . $e->getFile()
                . ' Line:' . $e->getLine()
                . ' Message:' . $e->getMessage());

            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong')
            ];
        }

        return $output;
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
        $business_id = request()->session()->get('user.business_id');
        if (! (auth()->user()->can('superadmin') || $this->moduleUtil->hasThePermissionInSubscription($business_id, 'crm_module'))) {
            abort(403, 'Unauthorized action.');
        }

        $users = User::forDropdown($business_id, false, false, false, true);
        $contacts  = Contact::customersDropdown($business_id, false);
        $sources = Category::forDropdown($business_id, 'source');
        $life_stages = Category::forDropdown($business_id, 'life_stage');

        $types['lead'] = __('crm::lang.lead');
        $store_action = action([\Modules\Crm\Http\Controllers\LeadController::class, 'store']);

        $module_form_parts = $this->moduleUtil->getModuleData('contact_form_part');

        return view('crm::surat_kontrak.create')
            ->with(compact('types', 'store_action', 'sources', 'life_stages', 'users', 'module_form_parts', 'contacts'));
    }
    public function import()
    {
        $business_id = request()->session()->get('user.business_id');
        if (! (auth()->user()->can('superadmin') || $this->moduleUtil->hasThePermissionInSubscription($business_id, 'crm_module'))) {
            abort(403, 'Unauthorized action.');
        }
        $listBatch=$this->getImportBatchList();


        return view('crm::surat_kontrak.import')
            ->with(compact('business_id', 'listBatch'));
    }
    public function prosesContactId($contactId)
    {
        if (empty($contactId)) {
            return null;
        }

        $contact = Contact::where('supplier_business_name', $contactId)->first();

        if ($contact) {
            return $contact->id;
        } else {
            // Jika tidak ditemukan, buat kontak baru
            $newContact = Contact::create([
                'supplier_business_name' => $contactId,
                'business_id' => auth()->user()->business_id,
                'type' => 'customer',
                'name' => $contactId,
                'email' => '-',
                'mobile' => '-',
                'created_by' => auth()->user()->id,
            ]);

            return $newContact->id;
        }
    }
    public function importProses(Request $request)
    {
        $request->validate([
            'import_file' => 'required|file|mimes:xlsx,xls'
        ]);

        try {

            DB::beginTransaction(); // 🔥 mulai transaction

            $file = $request->file('import_file');

            $rows = Excel::toArray([], $file)[0];
            $header = array_shift($rows);

            // ambil batch terakhir
            $lastBatch = SuratKontrak::max('import_batch');
            $currentBatch = $lastBatch ? $lastBatch + 1 : 1;

            $dataInsert = [];

            foreach ($rows as $row) {

                if (empty(array_filter($row))) {
                    continue;
                }

                $item = array_combine(
                    array_slice($header, 0, count($row)),
                    $row
                );

                $item['Selesai'] = is_int($item['Selesai'])
                    ? Date::excelToDateTimeObject($item['Selesai'])->format('Y-m-d')
                    : null;

                $item['Mulai Kerja'] = is_int($item['Mulai Kerja'])
                    ? Date::excelToDateTimeObject($item['Mulai Kerja'])->format('Y-m-d')
                    : null;

                $item['Contact ID'] = $this->prosesContactId($item['Contact ID'] ?? null);

                $dataInsert[] = [
                    'nomor' => $item['Nomor'] ?? null,
                    'contact_id' => $item['Contact ID'] ?? null,
                    'jabatan_pihak_2' => $item['Jabatan Pihak 2'] ?? null,
                    'pekerjaan' => $item['Pekerjaan'] ?? null,
                    'kapal_mv' => $item['Kapal / MV'] ?? null,
                    'tonase' => isset($item['Tonase']) && is_numeric($item['Tonase'])
    ? round((float)$item['Tonase'], -1)
    : null,
                    'lokasi_muat' => $item['Lokasi Muat'] ?? null,
                    'lokasi_bongkar' => $item['Lokasi Bongkar'] ?? null,
                    'mulai_kerja' => $item['Mulai Kerja'] ?? null,
                    'selesai' => $item['Selesai'] ?? null,
                    'jangka_waktu' => $item['Jangka Waktu'] ?? null,
                    'notes' => $item['Notes'] ?? null,
                    'business_id' => auth()->user()->business_id,
                    'import_batch' => $currentBatch,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (!empty($dataInsert)) {
                SuratKontrak::insert($dataInsert);
            }

            DB::commit(); // 🔥 commit kalau sukses

            return [
                'success' => true,
                'msg' => 'Surat kontrak berhasil diimport (Batch #' . $currentBatch . ')'
            ];
        } catch (\Exception $e) {

            DB::rollBack(); // 🔥 rollback kalau error

            \Log::emergency('File:' . $e->getFile()
                . ' Line:' . $e->getLine()
                . ' Message:' . $e->getMessage());

            return [
                'success' => false,
                'msg' => $e->getMessage()
            ];
        }
    }

    public function downloadSample(Request $request)
    {


        $data = [
            [
                "Nomor" => "001/SPK/BMT/2026",
                "Contact ID" => "PT. Dumai Bone Indonesia",
                "Jabatan Pihak 2" => "CPO",
                "Pekerjaan" => "Pengangkutan Cangkang",
                "Kapal / MV" => "MV. Sinar Laut",
                "Tonase" => "28129,22",
                "Lokasi Muat" => "Pelabuhan Dumai",
                "Lokasi Bongkar" => "Pelabuhan Belawan",
                "Mulai Kerja" => "2026-02-26",
                "Selesai" => "2026-02-28",
                "Jangka Waktu" => "2 Hari",
                "Notes" => "Pengiriman tahap pertama"
            ],
            [
                "Nomor" => "002/SPK/BMT/2026",
                "Contact ID" => "PT. Energi Samudra Logistik",
                "Jabatan Pihak 2" => "Manager Operasional",
                "Pekerjaan" => "Pengangkutan Batu Bara",
                "Kapal / MV" => "MV. Borneo Jaya",
                "Tonase" => "3829,22",
                "Lokasi Muat" => "Pelabuhan Tarahan",
                "Lokasi Bongkar" => "Pelabuhan Tanjung Priok",
                "Mulai Kerja" => "2026-03-05",
                "Selesai" => "2026-03-10",
                "Jangka Waktu" => "5 Hari",
                "Notes" => "Termasuk biaya sandar dan bongkar"
            ]
        ];

        $collection = collect($data);

        $collection->prepend(array_keys($data[0]));

        $fileName = 'sample_import_' . date('d-m-Y_H-i-s') . '.xlsx';

        return $collection->downloadExcel(
            $fileName,
            $writerType = null,
            $headings = false
        );
    }
    /**
     * Store a newly created resource in storage.
     *
     * @param  Request  $request
     * @return Response
     */
    public function store(Request $request)
    {
        $business_id = request()->session()->get('user.business_id');

        if (!(auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'crm_module'))) {
            abort(403, 'Unauthorized action.');
        }

        try {

            $input = $request->only([
                'nomor',
                'contact_id',
                'jabatan_pihak_2',
                'pekerjaan',
                'kapal_mv',
                'tonase',
                'lokasi_muat',
                'lokasi_bongkar',
                'mulai_kerja',
                'selesai',
                'jangka_waktu',
                'notes'
            ]);

            // Convert date format jika pakai format bisnis
            // $input['mulai_kerja'] = $this->commonUtil->uf_date($input['mulai_kerja']);
            // $input['selesai'] = $this->commonUtil->uf_date($input['selesai']);

            if (empty($input['nomor'])) {

                $year = date('Y', strtotime($input['mulai_kerja']));

                // Ambil nomor terakhir dengan format benar & tahun yang sama
                $last_record = SuratKontrak::whereYear('mulai_kerja', $year)
                    ->where('nomor', 'like', '%/SPK/BMT/' . $year)
                    ->orderByRaw("CAST(SUBSTRING_INDEX(nomor, '/', 1) AS UNSIGNED) DESC")
                    ->first();

                if ($last_record) {
                    $last_number = (int) explode('/', $last_record->nomor)[0];
                    $next_number = $last_number + 1;
                } else {
                    $next_number = 1;
                }

                $input['nomor'] = str_pad($next_number, 3, '0', STR_PAD_LEFT)
                    . '/SPK/BMT/' . $year;
            }

            $input['business_id'] = $business_id;
            $input['tonase'] = isset($input['tonase']) && is_numeric($input['tonase'])
                ? round((float)$input['tonase'], -1)
                : null;
            // $input['created_by'] = $request->session()->get('user.id');

            SuratKontrak::create($input);

            $output = [
                'success' => true,
                'msg' => 'Surat kontrak berhasil ditambahkan'
            ];
        } catch (\Exception $e) {
            dd($e);

            \Log::emergency('File:' . $e->getFile()
                . 'Line:' . $e->getLine()
                . 'Message:' . $e->getMessage());

            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong')
            ];
        }

        return $output;
    }

    /**
     * Show the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function show($id)
    {
        $business_id = request()->session()->get('user.business_id');

        if (!(auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'crm_module'))) {
            abort(403, 'Unauthorized action.');
        }

        $surat_kontrak = SuratKontrak::leftJoin('contacts', 'surat_kontrak.contact_id', '=', 'contacts.id')
            ->select('surat_kontrak.*', 'contacts.name', 'contacts.supplier_business_name', 'contacts.address_line_1', 'contacts.address_line_2')
            ->findOrFail($id);


        return view('crm::surat_kontrak.view')
            ->with(compact('surat_kontrak'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function edit($id)
    {
        $surat_kontrak = SuratKontrak::findOrFail($id);
        $business_id = request()->session()->get('user.business_id');
        $contacts  = Contact::customersDropdown($business_id, false);

        return view('crm::surat_kontrak.create')
            ->with(compact('surat_kontrak', 'contacts'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  Request  $request
     * @param  int  $id
     * @return Response
     */
    public function update(Request $request, $id)
    {
        $business_id = request()->session()->get('user.business_id');

        if (!(auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'crm_module'))) {
            abort(403, 'Unauthorized action.');
        }

        try {

            $surat_kontrak = SuratKontrak::where('business_id', $business_id)
                ->findOrFail($id);

            $input = $request->only([
                'nomor',
                'contact_id',
                'pekerjaan',
                'jabatan_pihak_2',
                'kapal_mv',
                'tonase',
                'lokasi_muat',
                'lokasi_bongkar',
                'mulai_kerja',
                'selesai',
                'jangka_waktu',
                'notes'
            ]);

            // Convert tanggal
            // if (!empty($input['mulai_kerja'])) {
            //     $input['mulai_kerja'] = $this->commonUtil->uf_date($input['mulai_kerja']);
            // }

            // if (!empty($input['selesai'])) {
            //     $input['selesai'] = $this->commonUtil->uf_date($input['selesai']);
            // }

            // Generate nomor jika kosong
            if (empty($input['nomor'])) {

                $year = date('Y', strtotime($input['mulai_kerja']));

                $last_record = SuratKontrak::where('business_id', $business_id)
                    ->whereYear('mulai_kerja', $year)
                    ->where('nomor', 'like', '%/SPK/BMT/' . $year)
                    ->orderByRaw("CAST(SUBSTRING_INDEX(nomor, '/', 1) AS UNSIGNED) DESC")
                    ->first();

                if ($last_record) {
                    $last_number = (int) explode('/', $last_record->nomor)[0];
                    $next_number = $last_number + 1;
                } else {
                    $next_number = 1;
                }

                $input['nomor'] = str_pad($next_number, 3, '0', STR_PAD_LEFT)
                    . '/SPK/BMT/' . $year;
            }

            $input['tonase'] = isset($input['tonase']) && is_numeric($input['tonase'])
            ? round((float)$input['tonase'], -1)
            : null;
            $surat_kontrak->update($input);

            $output = [
                'success' => true,
                'msg' => 'Surat kontrak berhasil diperbarui'
            ];
        } catch (\Exception $e) {

            \Log::emergency('File:' . $e->getFile()
                . ' Line:' . $e->getLine()
                . ' Message:' . $e->getMessage());

            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong')
            ];
        }

        return $output;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function destroy($id)
    {
        if (!request()->ajax()) {
            abort(404);
        }

        $business_id = request()->session()->get('user.business_id');

        if (!(auth()->user()->can('superadmin') ||
            $this->moduleUtil->hasThePermissionInSubscription($business_id, 'crm_module'))) {
            abort(403, 'Unauthorized action.');
        }

        try {

            $surat_kontrak = SuratKontrak::where('business_id', $business_id)
                ->findOrFail($id);

            $surat_kontrak->delete();

            $output = [
                'success' => true,
                'msg' => 'Surat kontrak berhasil dihapus'
            ];
        } catch (\Exception $e) {

            \Log::emergency('File:' . $e->getFile()
                . ' Line:' . $e->getLine()
                . ' Message:' . $e->getMessage());

            $output = [
                'success' => false,
                'msg' => __('messages.something_went_wrong')
            ];
        }

        return $output;
    }

    public function convertToCustomer($id)
    {
        $business_id = request()->session()->get('user.business_id');
        if (! (auth()->user()->can('superadmin') || $this->moduleUtil->hasThePermissionInSubscription($business_id, 'crm_module'))) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            try {
                $contact = CrmContact::where('business_id', $business_id)->findOrFail($id);

                $contact->type = 'customer';
                $contact->converted_by = auth()->user()->id;
                $contact->converted_on = \Carbon::now();
                $contact->save();

                $customer = Contact::find($contact->id);

                $this->commonUtil->activityLog($customer, 'converted', null, ['update_note' => __('crm::lang.converted_from_leads')]);

                $output = [
                    'success' => true,
                    'msg' => __('lang_v1.success'),
                ];
            } catch (\Exception $e) {
                \Log::emergency('File:' . $e->getFile() . 'Line:' . $e->getLine() . 'Message:' . $e->getMessage());

                $output = [
                    'success' => false,
                    'msg' => __('messages.something_went_wrong'),
                ];
            }

            return $output;
        }
    }

    public function postLifeStage($id)
    {
        $business_id = request()->session()->get('user.business_id');
        if (! (auth()->user()->can('superadmin') || $this->moduleUtil->hasThePermissionInSubscription($business_id, 'crm_module'))) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            try {
                $contact = CrmContact::where('business_id', $business_id)->findOrFail($id);

                $contact->crm_life_stage = request()->input('crm_life_stage');
                $contact->save();

                $output = [
                    'success' => true,
                    'msg' => __('lang_v1.success'),
                ];
            } catch (\Exception $e) {
                \Log::emergency('File:' . $e->getFile() . 'Line:' . $e->getLine() . 'Message:' . $e->getMessage());

                $output = [
                    'success' => false,
                    'msg' => __('messages.something_went_wrong'),
                ];
            }

            return $output;
        }
    }
}
