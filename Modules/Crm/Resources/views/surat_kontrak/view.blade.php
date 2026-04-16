<div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">

        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal">
                <span>&times;</span>
            </button>
            <h4 class="modal-title">Detail Surat Perjanjian Kerja (SPK)</h4>
        </div>

        <div class="modal-body ">
            <div class="p-4" id="print_area" style="font-family: Arial, sans-serif;">
                <div class="kop-container">
                    <div class="row align-items-bottom">
                        <div class="col-xs-3 text-center">
                            <img src="{{ asset('img/logo.png') }}" alt="Logo BMT" class="img-fluid">
                        </div>

                        <div class="col-xs-9 text-center" style="margin-left: -4rem">
                            <h1 class="company-name mb-0">CV. BM TRANSPORT</h1>
                            <h2 class="services mb-0">JASA ANGKUTAN & CONTRACTOR</h2>
                        </div>
                    </div>

                    <div class="row mt-2">
                        <div class="col-12 text-center">
                            <div class="footer-line"></div>
                            <div class="address-bar py-1">
                                JL. JERUK No. 48 A TELP. (0765) 38552 E-mail: cv.bmtransport@ymail.com – DUMAI
                            </div>
                            <hr style="border-top: 1px solid #333; margin-top: 0;">
                        </div>
                    </div>
                </div>

                <center>
                    <h4><b style="color: black">SURAT PERJANJIAN KERJA (SPK)</b></h4>
                    <p>
                        Nomor: {{ $surat_kontrak->nomor }}
                    </p>
                </center>

                <br>

                <table style="width: 100%" class="table-bordered" width="100%" border="1" cellpadding="5"
                    cellspacing="0">
                    <tr>
                        <td width="50%">
                            <b>PIHAK PERTAMA</b><br>
                            CV BM Transport<br>
                            Direktur<br>
                            Alamat: Jl Soekarno Hatta, Baganbesar, Simpang, Perwira, Kec. Dumai Tim., Riau 28826<br>

                        </td>
                        <td style="vertical-align: top" width="50%">
                            <b>PIHAK KEDUA</b><br>
                            {{ $surat_kontrak->supplier_business_name }}<br>
                            Jabatan: {{ $surat_kontrak->jabatan_pihak_2 }}<br>
                            Alamat : {{ $surat_kontrak->address_line_1 ?? $surat_kontrak->address_line_2 }}
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            Pekerjaan:<br>
                            <b> {{ $surat_kontrak->pekerjaan }}</b>
                        </td>
                    </tr>
                </table>

                <br>

                <b>DETAIL PEKERJAAN</b>

                <table width="100%" class="table-bordered" cellpadding="5" cellspacing="0">
                    <tr>
                        <td width="30%">Kapal MV</td>
                        <td>{{ $surat_kontrak->kapal_mv }}</td>
                    </tr>
                    <tr>
                        <td>Tonase</td>
                        <td>{{ $surat_kontrak->tonase }}</td>
                    </tr>
                    <tr>
                        <td>Lokasi Muat</td>
                        <td>{{ $surat_kontrak->lokasi_muat }}</td>
                    </tr>

                    <tr>
                        <td>Lokasi Bongkar</td>
                        <td>{{ $surat_kontrak->lokasi_bongkar }}</td>
                    </tr>
                    <tr>
                        <td>Jangka Waktu</td>
                        <td>{{ $surat_kontrak->jangka_waktu }}</td>
                    </tr>
                    <tr>
                        <td>Mulai Kerja</td>
                        <td>
                            {{ \Carbon\Carbon::parse($surat_kontrak->mulai_kerja)->format('d F Y') }}
                            s/d
                            {{ \Carbon\Carbon::parse($surat_kontrak->selesai)->format('d F Y') }}
                        </td>
                    </tr>
                </table>

                <br>

                <b class="">KETENTUAN PELAKSANAAN</b>
                <ol class="listnya" style="list-style: decimal;padding-left:5%">
                    <li>Pihak Kedua wajib melaksanakan pekerjaan sesuai standar operasional.</li>
                    <li>Pihak Kedua bertanggung jawab atas kelancaran pelaksanaan pekerjaan.</li>
                    <li>Segala hal teknis akan dikoordinasikan antara kedua belah pihak.</li>
                    <li>Setiap pembayaran atas pekerjaan yang dilaksanakan oleh Pihak Kedua akan dikenakan potongan Pajak Penghasilan (PPh) Pasal 23 sesuai dengan ketentuan perpajakan yang berlaku.</li>
                </ol>


                Demikian SPK ini dibuat dan disepakati bersama.

                <br>
                <br>

                <table width="100%">
                    <tr>
                        <td width="50%">
                            <b>PIHAK PERTAMA</b><br>
                            CV BM Transport<br>
                            Direktur
                            <br><br><br>
                            (___________________)
                        </td>
                        <td width="50%">
                            <b>PIHAK KEDUA</b><br>
                            {{ $surat_kontrak->supplier_business_name ?? '-' }}<br>
                            {{ $surat_kontrak->jabatan_pihak_2 }}
                            <br><br><br>
                            (___________________)
                        </td>
                    </tr>
                </table>

            </div>



        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-primary" onclick="$('#print_area').print()">
                <i class="fa fa-print"></i> Cetak
            </button>
            <button type="button" class="btn btn-default" data-dismiss="modal">
                Tutup
            </button>
        </div>

    </div>
</div>
