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

                <center>
                    <h4><b>SURAT PERJANJIAN KERJA (SPK)</b></h4>
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
                            {{ $surat_kontrak->pekerjaan }}
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

                <br><br>

                <b class="">KETENTUAN PELAKSANAAN</b>
                <ol class="listnya" style="list-style: decimal;padding-left:5%">
                    <li>Pihak Kedua wajib melaksanakan pekerjaan sesuai standar operasional.</li>
                    <li>Pihak Kedua bertanggung jawab atas kelancaran pelaksanaan pekerjaan.</li>
                    <li>Segala hal teknis akan dikoordinasikan antara kedua belah pihak.</li>
                    <li>Perselisihan diselesaikan secara musyawarah.</li>
                </ol>

                <br>

                Demikian SPK ini dibuat dan disepakati bersama.

                <br><br><br>

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
