<?php

namespace App\Http\Controllers;


use DB;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Yajra\DataTables\Facades\DataTables;

class DuplikatController extends Controller
{


    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        $list = [
            'accounts' => [
                'business_id',
                'created_by'
            ],
            'account_types' => [
                'business_id'
            ],
            'brands' => [
                'business_id',
                'created_by'
            ],
            'categories' => [
                'business_id',
                'created_by'
            ],
            'contacts' => [
                'business_id',
                'created_by'
            ],
            'tax_rates' => [
                'business_id',
                'created_by'
            ],
            'units' => [
                'business_id',
                'created_by'
            ],

        ];
        $value = [
            'business_id' => 2,
            'created_by' => 5
        ];
        foreach ($list as $table => $columns) {
            $query = DB::table($table)
                ->select();
            $awal = $query->get();
            $awal->transform(function ($item, $key) use ($columns, $value) {

                foreach ($columns as $column) {
                    $item->$column =  $value[$column];
                }
                unset($item->id);
                return (array)$item;
            });
            $newInstert = $awal->toArray();
            DB::table($table)->insert($newInstert);
        }
    }
}
