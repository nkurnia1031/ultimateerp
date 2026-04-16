<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Contact;
use App\Product;
use App\Tag;
use App\TaxRate;
use App\Events\ProductsCreatedOrModified;

use App\Transaction;
use App\TypesOfService;
use App\Unit;
use App\Utils\BusinessUtil;
use App\Utils\ModuleUtil;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use App\Utils\AccountTransactionUtil;
use App\Variation;
use DB;
use Excel;
use Illuminate\Http\Request;
use App\Events\PurchaseCreatedOrModified;


class ImportSalesController extends Controller
{
    /**
     * All Utils instance.
     */
    protected $productUtil;

    protected $businessUtil;

    protected $transactionUtil;

    protected $moduleUtil;
    protected $accountTransactionUtil;

    /**
     * Constructor
     *
     * @param  ProductUtils  $product
     * @return void
     */
    public function __construct(
        ProductUtil $productUtil,
        BusinessUtil $businessUtil,
        TransactionUtil $transactionUtil,
        AccountTransactionUtil $accountTransactionUtil,
        ModuleUtil $moduleUtil
    ) {
        $this->productUtil = $productUtil;
        $this->businessUtil = $businessUtil;
        $this->transactionUtil = $transactionUtil;
        $this->accountTransactionUtil = $accountTransactionUtil;
        $this->moduleUtil = $moduleUtil;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if (! auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $judul = "Import Penjualan";
        $type = 'sell';
        $link = 'import-sales';
        if (request()->is('import-pembelian')) {
            $judul = "Import Pembelian";
            $type = 'purchase';
            $link = 'import-pembelian';
        }
        $imported_sales = Transaction::where('business_id', $business_id)
            ->where('type', $type)
            ->whereNotNull('import_batch')
            ->with(['sales_person'])
            ->select('id', 'import_batch', 'import_time', 'import_file', 'invoice_no', 'ref_no', 'created_by')
            ->orderBy('import_batch', 'desc')
            ->get();

        $imported_sales_array = [];
        foreach ($imported_sales as $sale) {
            $imported_sales_array[$sale->import_batch]['import_time'] = $sale->import_time;
            $imported_sales_array[$sale->import_batch]['import_file'] = $sale->import_file;
            $imported_sales_array[$sale->import_batch]['created_by'] = $sale->sales_person->user_full_name;
            if (request()->is('import-pembelian')) {
                $imported_sales_array[$sale->import_batch]['invoices'][] = $sale->ref_no;
            } else {
                $imported_sales_array[$sale->import_batch]['invoices'][] = $sale->invoice_no;
            }
        }

        $import_fields = $this->__importFields();


        return view('import_sales.index')->with(compact('link', 'imported_sales_array', 'import_fields', 'judul'));
    }

    /**
     * Preview imported data and map columns with sale fields
     *
     * @return \Illuminate\Http\Response
     */
    public function preview(Request $request)
    {
        $judul = "Preview Imported Sales";
        $link = 'import-sales';

        if (request()->is('import-pembelian/preview')) {
            $judul = "Preview Import Purchases";
            $link = 'import-pembelian';
        }
        if (! auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }

        $notAllowed = $this->businessUtil->notAllowedInDemo();
        if (! empty($notAllowed)) {
            return $notAllowed;
        }

        $business_id = request()->session()->get('user.business_id');

        if ($request->hasFile('sales')) {
            $file_name = time() . '_' . $request->sales->getClientOriginalName();
            $request->sales->storeAs('temp', $file_name);

            $parsed_array = $this->__parseData($file_name);

            $import_fields = $this->__importFields();
            foreach ($import_fields as $key => $value) {
                $import_fields[$key] = $value['label'];
            }

            //Evaluate highest matching field with the header to pre select from dropdown
            $headers = $parsed_array[0];
            $match_array = [];
            foreach ($headers as $key => $value) {
                $match_percentage = [];
                foreach ($import_fields as $k => $v) {
                    similar_text($value, $v, $percentage);
                    $match_percentage[$k] = $percentage;
                }
                $max_key = array_keys($match_percentage, max($match_percentage))[0];

                //If match percentage is greater than 50% then pre select the value
                $match_array[$key] = $match_percentage[$max_key] >= 50 ? $max_key : null;
            }

            $business_locations = BusinessLocation::forDropdown($business_id);

            return view('import_sales.preview')->with(compact('link', 'judul', 'parsed_array', 'import_fields', 'file_name', 'business_locations', 'match_array'));
        }
    }

    public function __parseData($file_name)
    {
        $array = Excel::toArray([], public_path('uploads/temp/' . $file_name))[0];

        //remove blank columns from headers
        $headers = array_filter($array[0]);

        //Remove header row
        unset($array[0]);
        $parsed_array[] = $headers;
        foreach ($array as $row) {
            $temp = [];
            foreach ($row as $k => $v) {
                if (array_key_exists($k, $headers)) {
                    $temp[] = $v;
                }
            }
            $parsed_array[] = $temp;
        }

        return $parsed_array;
    }

    /**
     * Import sales to database
     *
     * @return \Illuminate\Http\Response
     */
    public function import(Request $request)
    {
        if (! auth()->user()->can('sell.create')) {
            abort(403, 'Unauthorized action.');
        }
        $link = 'import-sales';
        if (request()->is('import-pembelian')) {
            $link = 'import-pembelian';
        }

        try {
            DB::beginTransaction();
            // DB::enableQueryLog();

            $file_name = $request->input('file_name');
            $import_fields = $request->input('import_fields');
            $group_by = $request->input('group_by');
            $location_id = $request->input('location_id');
            $business_id = $request->session()->get('user.business_id');

            $file_path = public_path('uploads/temp/' . $file_name);
            $parsed_array = $this->__parseData($file_name);
            //Remove header row
            unset($parsed_array[0]);
            $formatted_sales_data = $this->__formatSaleData($parsed_array, $import_fields, $group_by);
            //Set maximum php execution time
            ini_set('max_execution_time', 0);
            ini_set('memory_limit', -1);

            $this->__importSales($formatted_sales_data, $business_id, $location_id, $file_name);
            // dd(collect(DB::getQueryLog())->take(100));

            DB::commit();

            $output = [
                'success' => 1,
                'msg' => __('lang_v1.sales_imported_successfully'),
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:' . $e->getFile() . 'Line:' . $e->getLine() . 'Message:' . $e->getMessage());
            $output = [
                'success' => 0,
                'msg' => $e->getMessage(),
            ];

            @unlink($file_path);

            return redirect($link)->with('notification', $output);
        }

        @unlink($file_path);

        return redirect($link)->with('status', $output);
    }

    private function __importSales($formated_data, $business_id, $location_id, $filename)
    {
        $import_batch = Transaction::where('business_id', $business_id)->max('import_batch');

        if (empty($import_batch)) {
            $import_batch = 1;
        } else {
            $import_batch = $import_batch + 1;
        }

        $now = \Carbon::now()->toDateTimeString();
        $row_index = 2;
        foreach ($formated_data as $data) {
            $order_total = 0;
            $sell_lines = [];
            foreach ($data as $line_data) {
                if (! empty($line_data['sku'])) {

                    $variation = Variation::where('sub_sku', $line_data['sku'])
                        ->whereHas('product', function ($query) use ($business_id) {
                            $query->where('business_id', $business_id);
                        })
                        ->with(['product'])
                        ->first();

                    $product = ! empty($variation) ? $variation->product : null;
                } else {
                    $product = Product::where('business_id', $business_id)
                        ->where('name', $line_data['product'])
                        ->with(['variations'])
                        ->first();
                    $variation = ! empty($product) ? $product->variations->first() : null;
                }

                if (empty($variation)) {
                    // throw new \Exception(__('lang_v1.import_sale_product_not_found', ['row' => $row_index, 'product_name' => $line_data['product'], 'sku' => $line_data['sku']]));
                    $unit_name = trim($line_data['unit']);
                    $unit = Unit::where('business_id', $business_id)
                        ->where(function ($query) use ($unit_name) {
                            $query->where('actual_name', $unit_name)
                                ->orWhere('short_name', $unit_name);
                        })
                        ->first();
                    if (empty($unit)) {
                        $unit = Unit::create([
                            'actual_name' => $unit_name,
                            'short_name' => $unit_name,
                            'allow_decimal' => 1,
                            'business_id' => $business_id,
                            'created_by' => auth()->user()->id,
                        ]);
                    }
                    $idUnit = $unit->id;
                    $product_details = [
                        'name' => $line_data['product'],
                        'business_id' => $business_id,
                        'type' => 'single',
                        'unit_id' =>   $idUnit,
                        'created_by' => auth()->user()->id,
                        'tax_type' => 'inclusive',
                        'barcode_type' => 'C128',

                        'sku' => $line_data['sku'] ?? ' ',
                    ];
                    if (request()->is('import-pembelian')) {
                        $product_details['enable_stock'] = 1;
                    }

                    $product = Product::create($product_details);
                    event(new ProductsCreatedOrModified($product_details, 'added'));

                    if (empty(trim($line_data['sku']))) {
                        $sku = $this->productUtil->generateProductSku($product->id);
                        $product->sku = $sku;
                        $product->save();
                    }

                    $this->productUtil->createSingleProductVariation(
                        $product->id,
                        $product->sku,
                        0,
                        0,
                        25,
                        0,
                        0
                    );
                    $variation = $product->variations->first();
                }

                $tax_id = null;
                $item_tax = 0;
                $line_discount = ! empty($line_data['item_discount']) ? $line_data['item_discount'] : 0;
                // $line_discount = $line_discount / 100;
                // $line_discount = $line_data['unit_price'] *  $line_discount;
                // $line_discount = $line_data['unit_price'] *  $line_discount;
                $unit_price = $line_data['unit_price'];

                $price_before_tax = $line_data['unit_price'] - ($line_discount);
                $price_inc_tax = $price_before_tax;
                if (! empty($line_data['item_tax'])) {
                    $tax = TaxRate::where('business_id', $business_id)
                        ->where('name', 'like', '%' . $line_data['item_tax'] . '%')
                        ->first();

                    if (empty($tax)) {
                        throw new \Exception(__('lang_v1.import_sale_tax_not_found', ['row' => $row_index, 'tax_name' => $line_data['item_tax']]));
                    }
                    $tax_id = $tax->id;
                    $item_tax = $this->transactionUtil->calc_percentage($price_before_tax, $tax->amount);
                    $price_inc_tax = $price_before_tax + $item_tax;
                }

                //check if date is correct
                if (! empty($line_data['date'])) {
                    try {
                        \Carbon::parse($line_data['date']);
                    } catch (\Exception $e) {
                        throw new \Exception(__('lang_v1.invalid_date_format_at', ['row' => $row_index]));
                    }
                }

                $temp = [
                    'product_id' => $variation->product_id,
                    'variation_id' => $variation->id,
                    'quantity' => $line_data['quantity'],
                    'unit_price' => $unit_price,
                    'unit_price_inc_tax' => $price_inc_tax,
                    'line_discount_type' => 'fixed',
                    'line_discount_amount' => $line_discount,
                    'item_tax' => $item_tax,
                    'tax_id' => $tax_id,
                    'sell_line_note' => $line_data['item_description'],
                    'product_unit_id' => $product->unit_id,
                    'enable_stock' => $product->enable_stock,
                    'type' => $product->type,
                    'combo_variations' => $product->type == 'combo' ? $variation->combo_variations : [],
                ];

                $line_quantity = $line_data['quantity'];
                if (! empty($line_data['unit'])) {
                    $unit_name = trim($line_data['unit']);
                    $unit = Unit::where('business_id', $business_id)
                        ->where(function ($query) use ($unit_name) {
                            $query->where('actual_name', $unit_name)
                                ->orWhere('short_name', $unit_name);
                        })
                        ->first();

                    if (empty($unit)) {
                        throw new \Exception(__('lang_v1.import_sale_unit_not_found', ['row' => $row_index, 'unit_name' => $unit_name]));
                    }

                    //Check if sub unit
                    if ($unit->id != $product->unit_id) {
                        $temp['sub_unit_id'] = $unit->id;
                        $temp['base_unit_multiplier'] = $unit->base_unit_multiplier;
                        $line_quantity = ($line_quantity * $unit->base_unit_multiplier);
                    }
                }
                $order_total += ($temp['unit_price_inc_tax'] * $line_quantity);


                $sell_lines[] = $temp;

                $row_index++;
            }

            $first_sell_line = $data[0];
            //get contact
            if (!empty($first_sell_line['customer_name'])) {
                $contact = Contact::where('business_id', $business_id)
                    ->where(function ($query) use ($first_sell_line) {
                        $query->where('supplier_business_name', $first_sell_line['customer_name'])
                            ->orWhere('name', $first_sell_line['customer_name']);
                    })
                    ->first();
            }
            if (empty($contact)) {
                $customer_name = ! empty($first_sell_line['customer_name']) ? $first_sell_line['customer_name'] : $first_sell_line['customer_phone_number'];
                $type = 'customer';
                if (request()->is('import-pembelian')) {
                    $type = 'supplier';
                }
                $contact = Contact::create([
                    'business_id' => $business_id,
                    'type' => $type,
                    'name' => $customer_name,
                    'supplier_business_name' => $customer_name,
                    'email' => $first_sell_line['customer_email'],
                    'mobile' => $first_sell_line['customer_phone_number'],
                    'created_by' => auth()->user()->id,
                ]);
            }

            $sale_data = [
                'invoice_no' => $first_sell_line['invoice_no'],
                'location_id' => $location_id,
                'status' => 'final',
                'contact_id' => $contact->id,
                'final_total' => ! empty($first_sell_line['order_total']) ? $first_sell_line['order_total'] : $order_total,
                'transaction_date' => ! empty($first_sell_line['date']) ? $first_sell_line['date'] : $now,
                'discount_amount' => 0,
                'import_batch' => $import_batch,
                'import_time' => $now,
                'import_file' => $filename,
                'commission_agent' => null,
                'document' => $first_sell_line['lampiran'] ?? null,
            ];

            $is_types_service_enabled = $this->moduleUtil->isModuleEnabled('types_of_service');
            if ($is_types_service_enabled && ! empty($first_sell_line['types_of_service'])) {
                $types_of_service = TypesOfService::where('business_id', $business_id)
                    ->where('name', $first_sell_line['types_of_service'])
                    ->first();

                if (empty($types_of_service)) {
                    throw new \Exception(__('lang_v1.types_of_servicet_not_found', ['row' => $row_index, 'types_of_service_name' => $first_sell_line['types_of_service']]));
                }

                $sale_data['types_of_service_id'] = $types_of_service->id;
                $sale_data['service_custom_field_1'] = ! empty($first_sell_line['service_custom_field1']) ? $first_sell_line['service_custom_field1'] : null;
                $sale_data['service_custom_field_2'] = ! empty($first_sell_line['service_custom_field2']) ? $first_sell_line['service_custom_field2'] : null;
                $sale_data['service_custom_field_3'] = ! empty($first_sell_line['service_custom_field3']) ? $first_sell_line['service_custom_field3'] : null;
                $sale_data['service_custom_field_4'] = ! empty($first_sell_line['service_custom_field4']) ? $first_sell_line['service_custom_field4'] : null;
            }

            $invoice_total = [
                'total_before_tax' => ! empty($first_sell_line['order_total']) ? $first_sell_line['order_total'] : $order_total,
                'tax' => 0,
            ];

            if (request()->is('import-pembelian')) {
                $sale_data['type'] = 'purchase';
                $purchases = array_map(function ($line) {
                    $line['purchase_line_tax_id'] = $line['tax_id'] ?? null;
                    unset($line['tax_id']);

                    $unit_price   = $line['unit_price'] ?? 0;
                    $quantity     = $line['quantity'] ?? 0;
                    $discount_total = $line['line_discount_amount'] ?? 0;

                    $line['pp_without_discount'] = $unit_price;

                    if ($unit_price > 0 && $quantity > 0) {
                        $total_price = $unit_price * $quantity;

                        if ($discount_total > 0 && $total_price > 0) {
                            $line['discount_percent'] = ($discount_total / $total_price) * 100;
                            $discount_per_unit = $discount_total / $quantity;
                            $line['purchase_price'] = $unit_price - $discount_per_unit;
                        } else {
                            $line['discount_percent'] = 0;
                            $line['purchase_price']   = $unit_price;
                        }
                    } else {
                        $line['discount_percent'] = 0;
                        $line['purchase_price']   = $unit_price;
                    }

                    $line['purchase_price_inc_tax'] = $line['unit_price_inc_tax'] ?? 0;
                    unset($line['unit_price_inc_tax']);

                    $line['profit_percent'] = 25;
                    $line['default_sell_price'] = $unit_price * 1.25;

                    unset(
                        $line['line_discount_type'],
                        $line['line_discount_amount'],
                        $line['sell_line_note'],
                        $line['enable_stock'],
                        $line['type'],
                        $line['combo_variations'],
                        $line['unit_price']
                    );

                    return $line;
                }, $sell_lines);
                $currency_details = $this->transactionUtil->purchaseCurrencyDetails($business_id);


                $enable_product_editing = request()->session()->get('business.enable_editing_product_from_purchase');
                // $sale_data['total_before_tax']= $invoice_total['total_before_tax'];
                $transaction = $this->transactionUtil->createSellTransaction($business_id, $sale_data, $invoice_total, auth()->user()->id, false);
                $this->productUtil->createOrUpdatePurchaseLines($transaction, $purchases, $currency_details, $enable_product_editing);
                $this->productUtil->adjustStockOverSelling($transaction);

                $this->transactionUtil->activityLog($transaction, 'added');

                PurchaseCreatedOrModified::dispatch($transaction);
            } else {
                $sale_data['type'] = 'sell';

                $transaction = $this->transactionUtil->createSellTransaction($business_id, $sale_data, $invoice_total, auth()->user()->id, false);
                $this->transactionUtil->createOrUpdateSellLines($transaction, $sell_lines, $location_id, false, null, [], false);
                foreach ($sell_lines as $line) {
                    if ($line['enable_stock']) {
                        $this->productUtil->decreaseProductQuantity(
                            $line['product_id'],
                            $line['variation_id'],
                            $location_id,
                            $line['quantity']
                        );
                    }

                    if ($line['type'] == 'combo') {
                        $line_total_quantity = $line['quantity'];
                        if (! empty($line['base_unit_multiplier'])) {
                            $line_total_quantity = $line_total_quantity * $line['base_unit_multiplier'];
                        }

                        //Decrease quantity of combo as well.
                        $combo_details = [];
                        foreach ($line['combo_variations'] as $combo_variation) {
                            $combo_variation_obj = Variation::find($combo_variation['variation_id']);

                            //Multiply both subunit multiplier of child product and parent product to the quantity
                            $combo_variation_quantity = $combo_variation['quantity'];
                            if (! empty($combo_variation['unit_id'])) {
                                $combo_variation_unit = Unit::find($combo_variation['unit_id']);
                                if (! empty($combo_variation_unit->base_unit_multiplier)) {
                                    $combo_variation_quantity = $combo_variation_quantity * $combo_variation_unit->base_unit_multiplier;
                                }
                            }

                            $combo_details[] = [
                                'product_id' => $combo_variation_obj->product_id,
                                'variation_id' => $combo_variation['variation_id'],
                                'quantity' => $combo_variation_quantity * $line_total_quantity,
                            ];
                        }

                        $this->productUtil
                            ->decreaseProductQuantityCombo(
                                $combo_details,
                                $location_id
                            );
                    }
                }
            }



            $payment_types = $this->transactionUtil->payment_types($transaction->location);

            $paid_on_raw = $first_sell_line['paid_on'] ?? null;

            if (!empty($paid_on_raw)) {
                try {
                    // coba parse dengan format DB (Y-m-d H:i:s)
                    $paid_on = \Carbon::createFromFormat('Y-m-d H:i:s', $paid_on_raw);
                } catch (\Exception $e) {
                    // gagal parse → fallback ke now
                    $paid_on = $now;
                }
            } else {
                // kosong → fallback ke now
                $paid_on = $now;
            }

            $paid_on = $paid_on->format('d-m-Y H:i');
            $payment = [
                "amount" =>  $transaction->final_total,
                "paid_on" => $paid_on,
                "method" => empty($first_sell_line['method']) ? null : $this->getPaymentKey($first_sell_line['method'], $payment_types),
                "account_id" => $this->accountTransactionUtil->getPaymentAccount($first_sell_line['account_id'], $business_id),
                "card_number" => null,
                "card_holder_name" => null,
                "card_transaction_number" => null,
                "card_type" => "credit",
                "card_month" => null,
                "card_year" => null,
                "card_security" => null,
                "cheque_number" => null,
                "bank_account_number" => null,
                "transaction_no_1" => null,
                "transaction_no_2" => null,
                "transaction_no_3" => null,
                "transaction_no_4" => null,
                "transaction_no_5" => null,
                "transaction_no_6" => null,
                "transaction_no_7" => null,
                "note" => $first_sell_line['note']
            ];
            $this->transactionUtil->createOrUpdatePaymentLines($transaction, [$payment]);
            if (!empty($first_sell_line['tags'])) {
                $tags = collect(explode(',', $first_sell_line['tags']))
                    ->map(fn($tag) => trim($tag))
                    ->filter()
                    ->map(function ($tag) use ($business_id) {
                        try {
                            return Tag::firstOrCreate([
                                'business_id' => $business_id,
                                'name'        => $tag,
                            ])->id;
                        } catch (\Throwable $e) {
                            \Log::error("Gagal membuat/menemukan tag: {$tag}", [
                                'business_id' => $business_id,
                                'error'       => $e->getMessage(),
                            ]);
                            return null; // skip kalau gagal
                        }
                    })
                    ->filter() // buang null hasil gagal
                    ->unique() // pastikan ID tidak dobel
                    ->values()
                    ->toArray();

                if (!empty($tags)) {
                    $this->transactionUtil->updateTagTransaction($transaction->id, $tags);
                }
            }


            //Update payment status
            $this->transactionUtil->updatePaymentStatus($transaction->id, $transaction->final_total);

            $business_details = $this->businessUtil->getDetails($business_id);
            $pos_settings = empty($business_details->pos_settings) ? $this->businessUtil->defaultPosSettings() : json_decode($business_details->pos_settings, true);

            $business = [
                'id' => $business_id,
                'accounting_method' => request()->session()->get('business.accounting_method'),
                'location_id' => $location_id,
                'pos_settings' => $pos_settings,
            ];
            $this->transactionUtil->mapPurchaseSell($business, $transaction->sell_lines, 'purchase');
        }
    }

    private function __formatSaleData($imported_data, $import_fields, $group_by)
    {
        $formatted_array = [];
        $invoice_number_key = array_search('invoice_no', $import_fields);
        $customer_name_key = array_search('customer_name', $import_fields);
        $customer_phone_key = array_search('customer_phone_number', $import_fields);
        $customer_email_key = array_search('customer_email', $import_fields);
        $date_key = array_search('date', $import_fields);
        $product_key = array_search('product', $import_fields);
        $sku_key = array_search('sku', $import_fields);
        $quantity_key = array_search('quantity', $import_fields);
        $unit_price_key = array_search('unit_price', $import_fields);
        $item_tax_key = array_search('item_tax', $import_fields);
        $item_discount_key = array_search('item_discount', $import_fields);
        $item_description_key = array_search('item_description', $import_fields);
        $order_total_key = array_search('order_total', $import_fields);
        $unit_key = array_search('unit', $import_fields);
        $tos_key = array_search('types_of_service', $import_fields);
        $service_custom_field1_key = array_search('service_custom_field1', $import_fields);
        $service_custom_field2_key = array_search('service_custom_field2', $import_fields);
        $service_custom_field3_key = array_search('service_custom_field3', $import_fields);
        $service_custom_field4_key = array_search('service_custom_field4', $import_fields);

        $paid_on = array_search('paid_on', $import_fields);
        $method = array_search('method', $import_fields);
        $account_id = array_search('account_id', $import_fields);
        $note = array_search('note', $import_fields);
        $tags = array_search('tags', $import_fields);
        $lampiran = array_search('lampiran', $import_fields);

        $row_index = 2;
        foreach ($imported_data as $key => $value) {
            $formatted_array[$key]['invoice_no'] = $invoice_number_key !== false ? $value[$invoice_number_key] : null;
            $formatted_array[$key]['customer_name'] = $customer_name_key !== false ? $value[$customer_name_key] : null;
            $formatted_array[$key]['customer_phone_number'] = $customer_phone_key !== false ? $value[$customer_phone_key] : null;
            $formatted_array[$key]['customer_email'] = $customer_email_key !== false ? $value[$customer_email_key] : null;
            $formatted_array[$key]['date'] = $date_key !== false ? $value[$date_key] : null;
            $formatted_array[$key]['product'] = $product_key !== false ? $value[$product_key] : null;
            $formatted_array[$key]['sku'] = $sku_key !== false ? $value[$sku_key] : null;
            $formatted_array[$key]['quantity'] = $quantity_key !== false ? $value[$quantity_key] : null;
            $formatted_array[$key]['unit_price'] = $unit_price_key !== false ? $value[$unit_price_key] : null;
            $formatted_array[$key]['item_tax'] = $item_tax_key !== false ? $value[$item_tax_key] : null;
            $formatted_array[$key]['item_discount'] = $item_discount_key !== false ? $value[$item_discount_key] : null;
            $formatted_array[$key]['item_description'] = $item_description_key !== false ? $value[$item_description_key] : null;
            $formatted_array[$key]['order_total'] = $order_total_key !== false ? $value[$order_total_key] : null;
            $formatted_array[$key]['unit'] = $unit_key !== false ? $value[$unit_key] : null;
            $formatted_array[$key]['types_of_service'] = $tos_key !== false ? $value[$tos_key] : null;
            $formatted_array[$key]['service_custom_field1'] = $service_custom_field1_key !== false ? $value[$service_custom_field1_key] : null;
            $formatted_array[$key]['service_custom_field2'] = $service_custom_field2_key !== false ? $value[$service_custom_field2_key] : null;
            $formatted_array[$key]['service_custom_field3'] = $service_custom_field3_key !== false ? $value[$service_custom_field3_key] : null;
            $formatted_array[$key]['service_custom_field4'] = $service_custom_field4_key !== false ? $value[$service_custom_field4_key] : null;
            $formatted_array[$key]['paid_on'] = $paid_on !== false ? $value[$paid_on] : null;
            $formatted_array[$key]['method'] = $method !== false ? $value[$method] : null;
            $formatted_array[$key]['account_id'] = $account_id !== false ? $value[$account_id] : null;
            $formatted_array[$key]['note'] = $note !== false ? $value[$note] : null;
            $formatted_array[$key]['tags'] = $tags !== false ? $value[$tags] : null;
            $formatted_array[$key]['lampiran'] = $lampiran !== false ? $value[$lampiran] : null;

            $formatted_array[$key]['group_by'] = $value[$group_by];

            //check empty
            // if (empty($formatted_array[$key]['customer_phone_number']) && empty($formatted_array[$key]['customer_email'])) {
            //     throw new \Exception(__('lang_v1.email_or_phone_cannot_be_empty_in_row', ['row' => $row_index]));
            // }
            if (empty($formatted_array[$key]['product']) && empty($formatted_array[$key]['sku'])) {
                throw new \Exception(__('lang_v1.product_cannot_be_empty_in_row', ['row' => $row_index]));
            }
            if (empty($formatted_array[$key]['quantity'])) {
                throw new \Exception(__('lang_v1.quantity_cannot_be_empty_in_row', ['row' => $row_index]));
            }
            if (empty($formatted_array[$key]['unit_price'])) {
                // throw new \Exception(__('lang_v1.unit_price_cannot_be_empty_in_row', ['row' => $row_index]));
            }

            $row_index++;
        }
        $group_by_key = $import_fields[$group_by];
        $formatted_data = [];
        foreach ($formatted_array as $array) {
            $formatted_data[$array['group_by']][] = $array;
        }

        return $formatted_data;
    }
    private function getPaymentKey(string $value, array $payments): string
    {
        $key = array_search($value, $payments, true);
        return $key !== false ? $key : "cash";
    }

    private function __importFields()
    {
        $fields = [
            'invoice_no' => ['label' => __('sale.invoice_no')],
            'customer_name' => ['label' => 'Customer Name'],
            'customer_phone_number' => ['label' => 'Customer Phone Number', 'instruction' => __('lang_v1.either_cust_email_or_phone_required')],
            'customer_email' => ['label' => 'Customer Email', 'instruction' => __('lang_v1.either_cust_email_or_phone_required')],
            'date' => ['label' => __('sale.sale_date'), 'instruction' => __('lang_v1.date_format_instruction')],
            'product' => ['label' => __('product.product_name'), 'instruction' => __('lang_v1.either_product_name_or_sku_required')],
            'sku' => ['label' => __('lang_v1.product_sku'), 'instruction' => __('lang_v1.either_product_name_or_sku_required')],
            'quantity' => ['label' => __('lang_v1.quantity'), 'instruction' => __('lang_v1.required')],
            'unit' => ['label' => __('lang_v1.product_unit')],
            'unit_price' => ['label' => __('sale.unit_price')],
            'item_tax' => ['label' => __('lang_v1.item_tax')],
            'item_discount' => ['label' => __('lang_v1.item_discount')],
            'item_description' => ['label' => __('lang_v1.item_description')],
            'order_total' => ['label' => __('lang_v1.order_total')],
            "paid_on" => ['label' => 'Paid on', 'instruction' => 'date time format should be "Y-m-d H:i:s" (2020-07-15 17:45:32)'],
            "method" => ['label' => 'Payment method'],
            "account_id" => ['label' => 'Payment Account'],
            "note" => ['label' => 'Payment Note'],
            "tags" => ['label' => 'Tags', 'instruction' => 'Pisahkan dengan koma(,) untuk multiple tags. Contoh: Operasional,BM 9174 LP'],
            "lampiran" => ['label' => 'lampiran', 'instruction' => 'input filename.extensi cth:invoice 123.pdf'],
        ];
        if (request()->is('import-pembelian') || request()->is('import-pembelian/*')) {
            $fields = array_map(function ($item) {
                $item['label'] = str_replace('Sale', 'Purchase', str_replace('Customer', 'Suplier', $item['label']));
                if (!empty($item['instruction'])) {

                    $item['instruction'] = str_replace('Sale', 'Purchase', str_replace('customer', 'Suplier', $item['instruction']));
                }

                return $item;
            }, $fields);
        }


        $is_types_service_enabled = $this->moduleUtil->isModuleEnabled('types_of_service');

        if ($is_types_service_enabled) {
            $fields['types_of_service'] = ['label' => __('lang_v1.types_of_service')];
            $fields['service_custom_field1'] = ['label' => __('lang_v1.service_custom_field_1')];
            $fields['service_custom_field2'] = ['label' => __('lang_v1.service_custom_field_2')];
            $fields['service_custom_field3'] = ['label' => __('lang_v1.service_custom_field_3')];
            $fields['service_custom_field4'] = ['label' => __('lang_v1.service_custom_field_4')];
        }

        return $fields;
    }

    /**
     * Deletes all sales from a batch
     *
     * @return \Illuminate\Http\Response
     */

    public function revertPembelianImport($batch)
    {
        return $this->revertSaleImport($batch);
    }
    public function revertSaleImport($batch)
    {
        if (! auth()->user()->can('sell.delete')) {
            abort(403, 'Unauthorized action.');
        }
        $type = 'sell';
        if (request()->is('revert-pembelian-import/*')) {
            $type = 'purchase';
        }
        if (request()->is('revert-expense-import/*')) {
            $type = 'expense';
        }


        $notAllowed = $this->businessUtil->notAllowedInDemo();

        try {
            $business_id = request()->session()->get('user.business_id');


            //Begin transaction
            DB::beginTransaction();
            if ($type == 'expense') {
                $sales = Transaction::where('business_id', $business_id)
                    ->where('type', $type)
                    ->where('import_batch', $batch)
                    ->delete();
            } else {
                $sales = Transaction::where('business_id', $business_id)
                    ->where('type', $type)
                    ->where('import_batch', $batch)
                    ->get();
                foreach ($sales as $sale) {
                    if (request()->is('revert-pembelian-import/*')) {
                        $this->transactionUtil->deletePurchase($business_id, $sale->id);
                    } else {
                        $this->transactionUtil->deleteSale($business_id, $sale->id);
                    }
                }
            }

            DB::commit();

            $output = ['success' => 1, 'msg' => __('lang_v1.import_reverted_successfully')];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:' . $e->getFile() . 'Line:' . $e->getLine() . 'Message:' . $e->getMessage());

            $output = [
                'success' => 0,
                'msg' => trans('messages.something_went_wrong'),
            ];
        }

        return back()->with('status', $output);
    }
}
