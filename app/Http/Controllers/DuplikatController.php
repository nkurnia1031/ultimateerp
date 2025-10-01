<?php

namespace App\Http\Controllers;


use DB;
use Illuminate\Support\Collection;
use App\TaxRate;

use Excel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Yajra\DataTables\Facades\DataTables;

class DuplikatController extends Controller
{

    public function index()
    {
        // $url = "https://jurnal-quickbook.jurnal.id/uploads/transaction_attachment/file/14873920/FJM_077.pdf";
        // $saveDir = __DIR__ . "/uploads/documents";

        // $result = $this->downloadAndSaveFile($url, $saveDir);

        // if ($result) {
        //     echo "File berhasil disimpan: " . $result;
        // } else {
        //     echo "Gagal download atau simpan file.";
        // }
        // return $this->indexPenjualan();
        // return $this->indexPembelian();
        return $this->indexTdl();
    }
    public function indexTdl()
    {
        // $tdl1 = file_get_contents('tdl-udah.json');
        // $tdl1 = json_decode($tdl1)->messages;
        // $tdl1 = collect($tdl1)->pluck('text')->unique();
        // $tdl1 =  $tdl1->map(function ($item) {
        //     return explode(' ', $item)[0] ?? $item;
        // })->unique();;
        $tdl2 = file_get_contents('tdl-export.json');
        $tdl2 = json_decode($tdl2)->messages;
        $endOf2023 = strtotime("2025-09-27 23:59:59");
        $endOf20232 = strtotime("2025-09-27 00:00:00");

        $tdl2 = collect($tdl2)->map(function ($item) {
            $value = $item->text;
            preg_match_all('/oklink.cfd[^\s"]+/', $value, $matches);
            if (!empty($matches[0])) {
                foreach ($matches[0] as $bot) {
                    // Bersihkan spasi, newline, non-breaking space, dsb.
                    $clean = trim(preg_replace('/\s+/u', '', $bot));

                    // Masukkan hanya kalau tidak kosong
                    if ($clean !== '') {
                        $item->text = 'https://' . $clean;;
                        return  $item;
                    }
                }
            }
        })->unique('text')->whereBetween('date',   [$endOf20232, $endOf2023]);
        dd($tdl2);
        foreach ($tdl2 as $key => $value) {
            $link = $value->text;
            echo "<a href='$link'>$link</a><br>";
        }
    }
    public function indexbiaya()
    {
        // 1. Baca isi file JSON "lampiran.json" lalu ubah jadi array
        //lokasi lamporan.json ada di folder public
        $journal = new Collection(json_decode(file_get_contents('jurnal.json'), true));
        $lampiran = new Collection(json_decode(file_get_contents('lampiran.json'), true));
        // $journal = $journal->filter(function ($item) {
        //     return str_contains($item['Deskripsi'], 'Faktur Pembelian');
        // });

        $tax = TaxRate::where('business_id', auth()->user()->business_id)->get();
        $namafile = 'biaya 2025.xlsx';
        $data = Excel::toArray([], public_path($namafile))[0];
        // 1. Buang 4 index pertama

        // 2. Ambil header (index pertama setelah slice)
        $header = array_shift($data);

        // 3. Tambahkan 3 header baru
        //   $header = array_merge($header, ['transaction_type', 'transaction_number', 'transaction_date']);
        $data = collect($data)->chunk(100);
        $jum1 = 1;
        $jum2 = 100;
        foreach ($data as $datas) {
            $data2 = [];


            foreach ($datas as $row) {


                $item = array_combine(array_slice($header, 0, count($row)), $row);
                // if ($item['Diskon'] > 0 || $item['Diskon Per Baris %'] != '0.00 %' || $item['Diskon %'] > 0) {
                //     dd($item);
                // } else {
                //     continue;
                // }
                // dd($item);
                $no = $item['Nomor'];
                // $item['Tanggal'] = \Carbon\Carbon::createFromFormat('d/m/Y', $item['Tanggal'])->format('Y-m-d');
                $journalNya1 = $journal->where('transaction_type', $item['Transaksi'])->filter(function ($item) use ($no) {
                    return str_contains($item['transaction_number'],  $no);
                });
                $jenis = $item['Transaksi'];
                $lampiranNya = $lampiran->filter(function ($item) use ($no, $jenis) {
                    if (!empty($item['entry'])) {
                        return str_contains($item['entry']['number'],   $jenis . ' #' . $no);
                    } else {
                        return false;
                    }
                });
                // dd($lampiranNya, $no, $jenis, $item);
                $filename = '';
                if ($lampiranNya->isNotEmpty()) {
                    $filename = pathinfo(parse_url($lampiranNya->first()['file']['url'], PHP_URL_PATH), PATHINFO_BASENAME);
                }
                $journalNya = $journalNya1->where('Debit', 0)->first();
                // if ($journalNya['Akun'] == 'Trade Payable') {
                //     dd($journalNya1);
                // }
                // dd($journalNya);
                // $item['Tanggal'] = \Carbon\Carbon::createFromFormat('m/d/Y', $item['Tanggal'])->format('Y-m-d');
                if ($journalNya1->isEmpty()) {
                    // dd($no);
                    $journalNya = [
                        'transaction_date' => date_create($item['Tanggal'])->format('Y-m-d'),
                        'Akun' => '',
                        'Deskripsi' => '',
                    ];
                    $method = '';
                } else {
                    $method = $journalNya['Akun'] == 'Cash' ? 'Cash' : 'Bank Transfer';
                }
                // dd($journalNya);



                $data2[] = [
                    "LOCATION" => 'CV BM TRANSPORT',
                    "CATEGORY" => $item['Kategori'],
                    "SUB-CATEGORY" => null,
                    "REFERENCE NO" => $item['Nomor'],
                    "EXPENSE DATE" => $item['Tanggal'],
                    "EXPENSE FOR" => null,
                    "EXPENSE FOR CONTACT ID" => $item['Supplier'],
                    "ATTACH DOCUMENT" => $filename,
                    "APPLICABLE TAX" => $item['Tax'],
                    "EXPENSE NOTE" => $item['Deskripsi'],
                    "TOTAL AMOUNT" => $item['Jumlah'],
                    "PAID AMOUNT" =>  $item['Jumlah'],
                    "PAID ON DATE" => $journalNya['transaction_date'] . " 00:00:00",
                    "PAYMENT METHOD" => $method,
                    "PAYMENT ACCOUND" => $journalNya['Akun'],
                    "PAYMENT NOTE" =>  $journalNya['Deskripsi'],
                ];
            }


            // $data = new Collection(json_decode(file_get_contents($namafile . '.json'), true));
            $export = collect($data2);
            // dd($data->where('PAYMENT ACCOUND', ''));

            $export->prepend(array_keys($export->first()));


            // 4. Download hasil collection sebagai file Excel (lampiran.xlsx)
            $export->storeExcel(
                'export/' . 'export_' . $namafile . ' ' . $jum1 . ' - ' . $jum2 . '.xlsx',
                $disk = null,
                $writerType = null,
                $headings = false
            );
            $jum1 = $jum2 + 1;
            $jum2 = $jum2 + 100;
        }
        return "selesai";
    }
    public function indexPenjualan2()
    {
        // 1. Baca isi file JSON "lampiran.json" lalu ubah jadi array
        //lokasi lamporan.json ada di folder public
        $journal = new Collection(json_decode(file_get_contents('jurnal.json'), true));
        $lampiran = new Collection(json_decode(file_get_contents('lampiran.json'), true));
        // $journal = $journal->filter(function ($item) {
        //     return str_contains($item['Deskripsi'], 'Faktur Pembelian');
        // });

        $tax = TaxRate::where('business_id', auth()->user()->business_id)->get();
        $namafile = 'sales_list_01-01-2025_04-09-2025.xlsx';
        $data = Excel::toArray([], public_path($namafile))[0];
        // 1. Buang 4 index pertama
        $data = array_slice($data, 4);

        // 2. Ambil header (index pertama setelah slice)
        $header = array_shift($data);

        // 3. Tambahkan 3 header baru
        //   $header = array_merge($header, ['transaction_type', 'transaction_number', 'transaction_date']);
        $data2 = [];
        foreach ($data as $row) {

            $item = array_combine(array_slice($header, 0, count($row)), $row);
            // if ($item['Diskon'] > 0 || $item['Diskon Per Baris %'] != '0.00 %' || $item['Diskon %'] > 0) {
            //     dd($item);
            // } else {
            //     continue;
            // }
            dd($item);
            $no = $item['Nomor Transaksi'];
            // $item['Tanggal'] = \Carbon\Carbon::createFromFormat('d/m/Y', $item['Tanggal'])->format('Y-m-d');
            $journalNya1 = $journal->filter(function ($item) use ($no) {
                return str_contains($item['Deskripsi'], 'Faktur Penjualan #' . $no);
            });

            $lampiranNya = $lampiran->filter(function ($item) use ($no) {
                if (!empty($item['entry'])) {
                    return str_contains($item['entry']['number'],  'Sales Invoice #' . $no);
                } else {
                    return false;
                }
            });
            $filename = '';
            if ($lampiranNya->isNotEmpty()) {
                $filename = pathinfo(parse_url($lampiranNya->first()['file']['url'], PHP_URL_PATH), PATHINFO_BASENAME);
            }
            $journalNya = $journalNya1->where('Kredit', 0)->first();
            // if ($journalNya['Akun'] == 'Trade Payable') {
            //     dd($journalNya1);
            // }
            // dd($journalNya);
            if ($journalNya1->isEmpty()) {
                // dd($no);
                $journalNya = [
                    'transaction_date' => $item['transaction_date'],
                    'Akun' => '',
                    'Deskripsi' => '',
                ];
                $method = '';
            } else {
                $method = $journalNya['Akun'] == 'Cash' ? 'Cash' : 'Bank Transfer';
            }
            // dd($journalNya);

            if (empty($item['tax_rate']) &&  $item['line_tax_amount'] > 0) {

                $item['tax_rate'] = $item['Total Pajak'] / $item['Jumlah Kena Pajak'];
                if ($item['tax_rate'] > 11) {
                    $item['tax_rate'] = 9;
                }
                // $item['tax_rate'] = round($item['tax_rate']);
            }
            if ($item['tax_rate'] == 2) {
                $item['tax_rate'] = -2;
            }
            $item['tax_rate'] = $tax->where('amount', $item['Tarif Pajak'])->first()->name ?? '';


            $data2[] = [
                "Invoice No." =>  $no,
                "Customer name" => $item['Nama Panggilan'],
                "Customer Phone number" => $item['Nomor Ponsel'],
                "Customer Email" => $item['Email'],
                "Sale Date" => $item['Tanggal'],
                "Product name" => $item['Nama Produk'],
                "Product SKU" => $item['Kode Produk'],
                "Quantity" => $item['Kuantitas'],
                "Product Unit" => $item['Satuan'],
                "Unit Price" => $item['Harga per Unit'],
                "Item Tax" => $item['tax_rate'],
                "Item Discount" => $item['Diskon %'],
                "Item Description" => $item['Deskripsi'],
                "Types of service" => '',
                "Custom Field 1" => '',
                "Custom Field 2" => '',
                "Custom Field 3" => '',
                "Custom Field 4" => '',
                "Paid on" => $journalNya['transaction_date'] . " 00:00:00",
                "Payment method" => $method,
                "Payment Account" => $journalNya['Akun'],
                "Payment Note" =>  $journalNya['Deskripsi'],
                "Tags" => $item['Tag'],
                "Lampiran" => $filename,
            ];
        }

        // $data = new Collection(json_decode(file_get_contents($namafile . '.json'), true));
        $data = collect($data2);
        $data->prepend(array_keys($data->first()));

        // 4. Download hasil collection sebagai file Excel (lampiran.xlsx)
        return ($data)->downloadExcel(
            $namafile . '.xlsx',
            $writerType = null,
            $headings = false
        );
    }
    public function indexPenjualan()
    {
        // 1. Baca isi file JSON "lampiran.json" lalu ubah jadi array
        //lokasi lamporan.json ada di folder public
        $journal = new Collection(json_decode(file_get_contents('jurnal.json'), true));
        $lampiran = new Collection(json_decode(file_get_contents('lampiran.json'), true));
        // $journal = $journal->filter(function ($item) {
        //     return str_contains($item['Deskripsi'], 'Faktur Pembelian');
        // });

        $tax = TaxRate::where('business_id', auth()->user()->business_id)->get();
        $namafile = 'penjualan 2022 page1';
        $data = new Collection(json_decode(file_get_contents($namafile . '.json'), true));
        $data = collect($data['sales_lists']['transactions']);
        // $filtered = $data->filter(function ($item) {
        //     return $item['discount'] > 0 || $item['discount_rate_percentage'] > 0;
        // });

        // dd($filtered);
        // 2. Transform setiap item di dalam collection
        $data->transform(function ($item, $key) use ($journal, $lampiran, $tax) {
            // Ambil hanya URL dari field "file"
            $item['transaction_type'] = 'purchase';
            $item['person'] = $item['person']['name'];
            $no = $item['transaction_no'];

            $journalNya1 = $journal->filter(function ($item) use ($no) {

                return str_contains($item['Deskripsi'], 'Faktur Penjualan #' . $no);
            });
            // dd($journalNya);
            $lampiranNya = $lampiran->filter(function ($item) use ($no) {
                if (!empty($item['entry'])) {
                    return str_contains($item['entry']['number'],  'Sales Invoice #' . $no);
                } else {
                    return false;
                }
            });
            $filename = '';
            if ($lampiranNya->isNotEmpty()) {

                // dd($lampiranNya);
                $filename = pathinfo(parse_url($lampiranNya->first()['file']['url'], PHP_URL_PATH), PATHINFO_BASENAME);
            }
            $journalNya = $journalNya1->where('Kredit', 0)->first();
            // if ($journalNya['Akun'] == 'Trade Payable') {
            //     dd($journalNya1);
            // }
            // dd($journalNya);
            if ($journalNya1->isEmpty()) {
                // dd($no);
                $journalNya = [
                    'transaction_date' => $item['transaction_date'],
                    'Akun' => '',
                    'Deskripsi' => '',
                ];
                $method = '';
            } else {
                $method = $journalNya['Akun'] == 'Cash' ? 'Cash' : 'Bank Transfer';
            }

            if (!is_array($item['tags'])) {
                $item['tags'] =  [$item['tags']];
            }
            if ($item['tax_rate'] == 2) {
                $item['tax_rate'] = -2;
            }
            if (empty($item['tax_rate']) &&  $item['line_tax_amount'] > 0) {

                $item['tax_rate'] = $item['taxable_amount_per_line'] / $item['line_tax_amount'];
                if ($item['tax_rate'] > 11) {
                    $item['tax_rate'] = 9;
                }
                // $item['tax_rate'] = round($item['tax_rate']);
            }
            // if (empty($item['tax_rate']) &&  $item['line_tax_amount'] == 0) {
            //     $item['tax_rate'] = 9;
            // }
            // if ($item['tax_rate'] == 9) {
            //     $item['tax_rate'] = $tax->where('amount', $item['tax_rate'])->first()->name ?? '';
            //     dd($item['tax_rate']);
            // }

            if ($item['transaction_no'] == '01/BMT-KW/I/2022') {
                dd($item);
            }
            $item['tax_rate'] = $tax->where('amount', $item['tax_rate'])->first()->name ?? '';

            $header = [
                "Invoice No." => $item['transaction_no'],
                "Customer name" => $item['person'],
                "Customer Phone number" => ' ',
                "Customer Email" => $item['email'],
                "Sale Date" => $item['transaction_date'] . " 00:00:00",
                "Product name" => $item['product']['name'],
                "Product SKU" => '',
                "Quantity" => $item['quantity_unit'],
                "Product Unit" => $item['product_unit_name'],
                "Unit Price" => $item['unit_price'],
                "Item Tax" => $item['tax_rate'],
                "Item Discount" => $item['discount_rate_percentage'],
                "Item Description" => $item['memo'],
                "Types of service" => '',
                "Custom Field 1" => '',
                "Custom Field 2" => '',
                "Custom Field 3" => '',
                "Custom Field 4" => '',
                "Paid on" => $journalNya['transaction_date'] . " 00:00:00",
                "Payment method" => $method,
                "Payment Account" => $journalNya['Akun'],
                "Payment Note" =>  $journalNya['Deskripsi'],
                "Tags" => implode(',', $item['tags']),
                "Lampiran" => $filename,
            ];

            return  $header;
        });
        dd($data->where('Item Tax', ''));
        // 3. Tambahkan header (nama kolom) di awal data
        //    Caranya: ambil key dari item pertama, lalu prepend ke collection
        $data->prepend(array_keys($data->first()));

        // 4. Download hasil collection sebagai file Excel (lampiran.xlsx)
        return ($data)->downloadExcel(
            $namafile . '.xlsx',
            $writerType = null,
            $headings = false
        );
    }
    public function indexPembelian2()
    {
        // 1. Baca isi file JSON "lampiran.json" lalu ubah jadi array
        //lokasi lamporan.json ada di folder public
        $journal = new Collection(json_decode(file_get_contents('jurnal.json'), true));
        $lampiran = new Collection(json_decode(file_get_contents('lampiran.json'), true));
        // $journal = $journal->filter(function ($item) {
        //     return str_contains($item['Deskripsi'], 'Faktur Pembelian');
        // });

        $tax = TaxRate::where('business_id', auth()->user()->business_id)->get();
        $namafile = 'purchases_list_01-01-2025_31-12-2025.xlsx';
        $data = Excel::toArray([], public_path($namafile))[0];
        // 1. Buang 4 index pertama
        $data = array_slice($data, 4);

        // 2. Ambil header (index pertama setelah slice)
        $header = array_shift($data);

        // 3. Tambahkan 3 header baru
        //   $header = array_merge($header, ['transaction_type', 'transaction_number', 'transaction_date']);
        $data2 = [];
        foreach ($data as $row) {
            $item = array_combine(array_slice($header, 0, count($row)), $row);
            if ($item['Diskon'] > 0 || $item['Diskon Per Baris %'] != '0.00 %' || $item['Diskon %'] > 0) {
                dd($item);
            } else {
                continue;
            }
            $no = $item['Nomor Transaksi'];
            // $item['Tanggal'] = \Carbon\Carbon::createFromFormat('d/m/Y', $item['Tanggal'])->format('Y-m-d');
            $item['tax_rate'] = $tax->where('amount', $item['Tarif Pajak'])->first()->name ?? '';
            $journalNya1 = $journal->filter(function ($item) use ($no) {
                return str_contains($item['Deskripsi'], 'Faktur Pembelian #' . $no);
            });

            $lampiranNya = $lampiran->filter(function ($item) use ($no) {
                if (!empty($item['entry'])) {
                    return str_contains($item['entry']['number'],  'Purchase Invoice #' . $no);
                } else {
                    return false;
                }
            });
            $filename = '';
            if ($lampiranNya->isNotEmpty()) {
                $filename = pathinfo(parse_url($lampiranNya->first()['file']['url'], PHP_URL_PATH), PATHINFO_BASENAME);
            }
            $journalNya = $journalNya1->where('Debit', 0)->first();
            // if ($journalNya['Akun'] == 'Trade Payable') {
            //     dd($journalNya1);
            // }
            // dd($journalNya);
            if ($journalNya1->isEmpty()) {
                // dd($no);
                $journalNya = [
                    'transaction_date' => $item['transaction_date'],
                    'Akun' => '',
                    'Deskripsi' => '',
                ];
                $method = '';
            } else {
                $method = $journalNya['Akun'] == 'Cash' ? 'Cash' : 'Bank Transfer';
            }
            // dd($journalNya);

            $data2[] = [
                "Invoice No." =>  $no,
                "Suplier name" => $item['Nama Panggilan'],
                "Suplier Phone number" => $item['Nomor Ponsel'],
                "Suplier Email" => $item['Email'],
                "Sale Date" => $item['Tanggal'],
                "Product name" => $item['Nama Produk'],
                "Product SKU" => $item['Kode Produk'],
                "Quantity" => $item['Kuantitas'],
                "Product Unit" => $item['Satuan'],
                "Unit Price" => $item['Harga per Unit'],
                "Item Tax" => $item['tax_rate'],
                "Item Discount" => str_replace('%', '', $item['Diskon Per Baris %']),
                "Item Description" => $item['Deskripsi'],
                "Types of service" => '',
                "Custom Field 1" => '',
                "Custom Field 2" => '',
                "Custom Field 3" => '',
                "Custom Field 4" => '',
                "Paid on" => $journalNya['transaction_date'] . " 00:00:00",
                "Payment method" => $method,
                "Payment Account" => $journalNya['Akun'],
                "Payment Note" =>  $journalNya['Deskripsi'],
                "Tags" => $item['Tag'],
                "Lampiran" => $filename,
            ];
        }

        // $data = new Collection(json_decode(file_get_contents($namafile . '.json'), true));
        $data = collect($data2);
        $data->prepend(array_keys($data->first()));

        // 4. Download hasil collection sebagai file Excel (lampiran.xlsx)
        return ($data)->downloadExcel(
            $namafile . '.xlsx',
            $writerType = null,
            $headings = false
        );
    }
    public function indexPembelian()
    {
        // 1. Baca isi file JSON "lampiran.json" lalu ubah jadi array
        //lokasi lamporan.json ada di folder public
        $journal = new Collection(json_decode(file_get_contents('jurnal.json'), true));
        $lampiran = new Collection(json_decode(file_get_contents('lampiran.json'), true));
        // $journal = $journal->filter(function ($item) {
        //     return str_contains($item['Deskripsi'], 'Faktur Pembelian');
        // });

        $tax = TaxRate::where('business_id', auth()->user()->business_id)->get();
        $namafile = 'pembelian 2022 page1';
        $data = new Collection(json_decode(file_get_contents($namafile . '.json'), true));
        $data = collect($data['purchases_lists']['transactions']);
        $filtered = $data->filter(function ($item) {
            return $item['discount'] > 0 || $item['discount_rate_percentage'] > 0;
        });

        // dd($filtered);
        // 2. Transform setiap item di dalam collection
        $data->transform(function ($item, $key) use ($journal, $lampiran, $tax) {
            // Ambil hanya URL dari field "file"
            $item['transaction_type'] = 'purchase';
            $item['person'] = $item['person']['name'];
            $no = $item['transaction_no'];

            $journalNya1 = $journal->filter(function ($item) use ($no) {
                return str_contains($item['Deskripsi'], 'Faktur Pembelian #' . $no);
            });

            $lampiranNya = $lampiran->filter(function ($item) use ($no) {
                if (!empty($item['entry'])) {
                    return str_contains($item['entry']['number'],  'Purchase Invoice #' . $no);
                } else {
                    return false;
                }
            });
            $filename = '';
            if ($lampiranNya->isNotEmpty()) {

                $filename = pathinfo(parse_url($lampiranNya->first()['file']['url'], PHP_URL_PATH), PATHINFO_BASENAME);
            }
            $journalNya = $journalNya1->where('Debit', 0)->first();
            // if ($journalNya['Akun'] == 'Trade Payable') {
            //     dd($journalNya1);
            // }
            // dd($journalNya);
            if ($journalNya1->isEmpty()) {
                // dd($no);
                $journalNya = [
                    'transaction_date' => $item['transaction_date'],
                    'Akun' => '',
                    'Deskripsi' => '',
                ];
                $method = '';
            } else {
                $method = $journalNya['Akun'] == 'Cash' ? 'Cash' : 'Bank Transfer';
            }
            // dd($journalNya);
            if (!is_array($item['tags'])) {
                $item['tags'] =  [$item['tags']];
            }
            if ($item['tax_rate'] == 2) {
                $item['tax_rate'] = -2;
            }
            if (empty($item['tax_rate']) &&  $item['line_tax_amount'] > 0) {

                $item['tax_rate'] = $item['taxable_amount_per_line'] / $item['line_tax_amount'];
                if ($item['tax_rate'] > 11) {
                    $item['tax_rate'] = 9;
                }
            }
            // if (empty($item['tax_rate']) &&  $item['line_tax_amount'] == 0) {
            //     $item['tax_rate'] = 9;
            // }
            // if ($item['tax_rate'] == 9) {
            //     $item['tax_rate'] = $tax->where('amount', $item['tax_rate'])->first()->name ?? '';
            //     dd($item['tax_rate']);
            // }

            // if ($item['transaction_no'] == '10292') {
            //     dd($item);
            // }
            $item['tax_rate'] = $tax->where('amount', $item['tax_rate'])->first()->name ?? '';

            $header = [
                "Invoice No." => $item['transaction_no'],
                "Suplier name" => $item['person'],
                "Suplier Phone number" => ' ',
                "Suplier Email" => $item['email'],
                "Sale Date" => $item['transaction_date'] . " 00:00:00",
                "Product name" => $item['product']['name'],
                "Product SKU" => '',
                "Quantity" => $item['quantity_unit'],
                "Product Unit" => $item['product_unit_name'],
                "Unit Price" => $item['unit_price'],
                "Item Tax" => $item['tax_rate'],
                "Item Discount" => $item['discount_rate_percentage'],
                "Item Description" => $item['memo'],
                "Types of service" => '',
                "Custom Field 1" => '',
                "Custom Field 2" => '',
                "Custom Field 3" => '',
                "Custom Field 4" => '',
                "Paid on" => $journalNya['transaction_date'] . " 00:00:00",
                "Payment method" => $method,
                "Payment Account" => $journalNya['Akun'],
                "Payment Note" =>  $journalNya['Deskripsi'],
                "Tags" => implode(',', $item['tags']),
                "Lampiran" => $filename,
            ];

            return  $header;
        });
        dd($data->where('Item Tax', ''));


        // 3. Tambahkan header (nama kolom) di awal data
        //    Caranya: ambil key dari item pertama, lalu prepend ke collection
        $data->prepend(array_keys($data->first()));

        // 4. Download hasil collection sebagai file Excel (lampiran.xlsx)
        return ($data)->downloadExcel(
            $namafile . '.xlsx',
            $writerType = null,
            $headings = false
        );
    }
    public function converJurnal()
    {
        $array = Excel::toArray([], public_path('journal_01-01-2021_31-12-2025.xlsx'))[0];

        // 1. Buang 4 index pertama
        $array = array_slice($array, 5);

        // 2. Ambil header (index pertama setelah slice)
        $header = array_shift($array);

        // 3. Tambahkan 3 header baru
        $header = array_merge($header, ['transaction_type', 'transaction_number', 'transaction_date']);

        $results = [];
        $currentTransaction = [
            'transaction_type'   => null,
            'transaction_number' => null,
            'transaction_date'   => null,
        ];

        // 4. Looping array
        foreach ($array as $row) {
            // cek kalau semua null kecuali index ke 1
            if (
                (empty($row[0]) && !empty($row[1]) && empty($row[2]) && empty($row[3]) && empty($row[4]))
            ) {
                // format contoh: "Expense #10533 | 02/01/2022"
                preg_match('/(.*?) #(\d+) \| (.*)/', $row[1], $matches);
                if ($matches) {
                    $currentTransaction['transaction_type']   = trim($matches[1]);
                    $currentTransaction['transaction_number'] = $matches[2];
                    $currentTransaction['transaction_date']   = \Carbon\Carbon::createFromFormat('d/m/Y', $matches[3])->format('Y-m-d');
                }
                continue;
            }

            // skip baris Total
            if (isset($row[0]) && strtolower(trim($row[0])) === 'total') {
                continue;
            }

            // kalau baris data normal → gabungkan dengan header
            if (!empty(array_filter($row))) {
                $rowAssoc = array_combine(array_slice($header, 0, count($row)), $row);

                // tambah info transaksi
                $rowAssoc['transaction_type']   = $currentTransaction['transaction_type'];
                $rowAssoc['transaction_number'] = $currentTransaction['transaction_number'];
                $rowAssoc['transaction_date']   = $currentTransaction['transaction_date'];

                $results[] = $rowAssoc;
            }
        }

        file_put_contents('jurnal.json', json_encode($results));
    }
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index2()
    {
        $list = [
            // 'products' => [
            //     'business_id',
            //     'created_by'
            // ],
            // 'account_types' => [
            //     'business_id'
            // ],
            // 'brands' => [
            //     'business_id',
            //     'created_by'
            // ],
            // 'categories' => [
            //     'business_id',
            //     'created_by'
            // ],
            // 'contacts' => [
            //     'business_id',
            //     'created_by'
            // ],
            // 'tax_rates' => [
            //     'business_id',
            //     'created_by'
            // ],
            // 'units' => [
            //     'business_id',
            //     'created_by'
            // ],

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
    function downloadAndSaveFile(string $url, string $destinationDir): ?string
    {
        // Pastikan folder tujuan ada
        if (!is_dir($destinationDir)) {
            if (!mkdir($destinationDir, 0777, true) && !is_dir($destinationDir)) {
                throw new RuntimeException(sprintf('Directory "%s" was not created', $destinationDir));
            }
        }

        // Ambil konten file
        $fileContent = @file_get_contents($url);
        if ($fileContent === false) {
            return null; // gagal download
        }

        // Ambil nama file asli dari URL
        $originalName = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_BASENAME);

        // Generate unique ID + nama baru
        $uniqueName = uniqid() . '_' . $originalName;

        // Simpan ke folder tujuan
        $targetPath = rtrim($destinationDir, '/') . '/' . $uniqueName;
        if (file_put_contents($targetPath, $fileContent) === false) {
            return null; // gagal simpan
        }

        return $uniqueName;
    }
}
