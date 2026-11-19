@extends('layouts.app')
@section('title', 'Audit Keamanan')

@section('css')
<style>
    .security-shell { --security-accent: #dc2626; }
    .security-hero { background: linear-gradient(135deg, #111827 0%, #1f2937 58%, #312e81 100%); color: #fff; border-radius: 18px; padding: 26px; box-shadow: 0 18px 45px rgba(15, 23, 42, .16); overflow: hidden; position: relative; }
    .security-hero:after { content: ''; position: absolute; width: 280px; height: 280px; border-radius: 999px; right: -100px; top: -150px; background: rgba(255,255,255,.08); }
    .security-eyebrow { color: #a5b4fc; font-size: 12px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; }
    .security-title { color: #fff; font-size: 28px; font-weight: 800; margin: 5px 0 8px; }
    .security-subtitle { color: #d1d5db; max-width: 760px; line-height: 1.6; margin: 0; }
    .security-toolbar { display: flex; flex-wrap: wrap; gap: 10px; justify-content: flex-end; position: relative; z-index: 2; }
    .security-btn { border-radius: 10px !important; font-weight: 700 !important; padding: 10px 15px !important; }
    .security-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 15px; box-shadow: 0 7px 22px rgba(15,23,42,.05); margin-bottom: 18px; }
    .security-card-header { padding: 18px 20px 13px; border-bottom: 1px solid #eef2f7; }
    .security-card-header h3 { margin: 0; font-size: 17px; font-weight: 800; color: #111827; }
    .security-card-body { padding: 20px; }
    .security-stat { min-height: 128px; padding: 18px; border-radius: 14px; background: #fff; border: 1px solid #e5e7eb; margin-bottom: 18px; }
    .security-stat-label { color: #6b7280; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .07em; }
    .security-stat-value { font-size: 31px; line-height: 1; font-weight: 900; color: #111827; margin: 12px 0 7px; }
    .security-stat-note { color: #6b7280; font-size: 12px; line-height: 1.4; }
    .security-risk { display: inline-flex; align-items: center; gap: 8px; border-radius: 999px; padding: 7px 12px; font-size: 12px; font-weight: 900; letter-spacing: .06em; text-transform: uppercase; }
    .security-risk-critical { background: #fee2e2; color: #991b1b; }
    .security-risk-high { background: #ffedd5; color: #9a3412; }
    .security-risk-medium { background: #fef3c7; color: #92400e; }
    .security-risk-low { background: #dcfce7; color: #166534; }
    .security-dot { width: 8px; height: 8px; border-radius: 99px; background: currentColor; box-shadow: 0 0 0 4px rgba(255,255,255,.7); }
    .security-assessment { border-left: 5px solid var(--security-accent); background: #fff7f7; border-radius: 12px; padding: 17px 18px; color: #374151; line-height: 1.55; }
    .security-assessment strong { display: block; color: #991b1b; font-size: 16px; margin-bottom: 5px; }
    .security-meta { display: grid; grid-template-columns: 150px minmax(0, 1fr); gap: 9px 16px; font-size: 13px; }
    .security-meta dt { color: #6b7280; }
    .security-meta dd { margin: 0; color: #111827; word-break: break-all; font-weight: 600; }
    .security-rec { display: flex; gap: 13px; padding: 14px 0; border-bottom: 1px solid #eef2f7; }
    .security-rec:last-child { border-bottom: 0; padding-bottom: 0; }
    .security-rec-priority { flex: 0 0 72px; height: fit-content; text-align: center; border-radius: 7px; padding: 5px 7px; background: #eef2ff; color: #4338ca; font-size: 11px; font-weight: 800; }
    .security-rec h4 { margin: 0 0 4px; font-size: 14px; font-weight: 800; color: #111827; }
    .security-rec p { margin: 0; color: #6b7280; line-height: 1.5; font-size: 13px; }
    .security-path { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 12px; word-break: break-all; color: #1f2937; }
    .security-badge { display: inline-block; padding: 4px 8px; border-radius: 7px; font-size: 11px; font-weight: 800; white-space: nowrap; }
    .security-badge-danger { background: #fee2e2; color: #991b1b; }
    .security-badge-warning { background: #ffedd5; color: #9a3412; }
    .security-badge-info { background: #e0e7ff; color: #3730a3; }
    .security-badge-muted { background: #f3f4f6; color: #4b5563; }
    .security-badge-success { background: #dcfce7; color: #166534; }
    .security-filters { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
    .security-filters .form-control { min-width: 170px; border-radius: 9px; }
    .security-table details summary { cursor: pointer; color: #4f46e5; font-weight: 700; }
    .security-table .detail-copy { max-width: 520px; padding-top: 8px; color: #4b5563; line-height: 1.5; white-space: normal; }
    .security-empty { text-align: center; padding: 58px 25px; }
    .security-empty-icon { margin: 0 auto 16px; width: 64px; height: 64px; display: grid; place-items: center; border-radius: 18px; background: #eef2ff; color: #4338ca; font-size: 27px; }
    .security-root-warning { border-radius: 10px; padding: 12px 14px; background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; font-size: 13px; }
    .security-cleanup-box { background: #fff; border: 1px solid #fecaca; border-radius: 14px; padding: 18px; }
    .security-cleanup-box h3 { color: #991b1b; font-size: 17px; font-weight: 900; margin: 0 0 7px; }
    .security-cleanup-box p { color: #6b7280; line-height: 1.5; }
    .security-command { display: block; overflow-wrap: anywhere; padding: 12px; border-radius: 9px; background: #f3f4f6; color: #111827; }
    .security-history-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 0; border-bottom: 1px solid #eef2f7; }
    .security-history-row:last-child { border-bottom: 0; }
    .security-history-name { font-weight: 800; color: #111827; word-break: break-word; }
    .security-history-meta { color: #6b7280; font-size: 12px; margin-top: 3px; }
    @media (max-width: 767px) { .security-toolbar { justify-content: flex-start; margin-top: 18px; } .security-meta { grid-template-columns: 1fr; gap: 3px; } .security-title { font-size: 23px; } }
</style>
@endsection

@section('content')
@php
    $riskLabels = ['critical' => 'Kritis', 'high' => 'Tinggi', 'medium' => 'Sedang', 'low' => 'Rendah'];
    $severityLabels = ['critical' => 'Kritis', 'high' => 'Tinggi', 'medium' => 'Sedang', 'low' => 'Rendah'];
    $currentAssessment = $report['assessment'] ?? null;
@endphp
<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">Audit Keamanan</h1>
    <p class="tw-text-sm tw-text-gray-500 tw-mt-1">Baca hasil pemeriksaan, pisahkan indikasi kompromi dari noise, dan tangani artefak berbahaya secara terverifikasi.</p>
</section>

<section class="content security-shell" id="security-audit-page"
    data-upload-url="{{ route('security-audit.upload') }}">
    <div class="security-hero tw-mb-5">
        <div class="row tw-items-center">
            <div class="col-md-7">
                <div class="security-eyebrow">Incident review console</div>
                <h2 class="security-title">Satu tempat untuk memahami kondisi server</h2>
                <p class="security-subtitle">Laporan hanya dibaca sebagai data—isinya tidak pernah dieksekusi. Jalankan scanner Python di terminal server, lalu unggah JSON untuk ditinjau di sini.</p>
            </div>
            <div class="col-md-5">
                <div class="security-toolbar">
                    <button type="button" class="btn btn-default security-btn" data-toggle="modal" data-target="#upload-audit-modal">
                        <i class="fas fa-file-import tw-mr-1"></i> Baca JSON
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="security-card">
        <div class="security-card-body">
            <div class="row tw-items-center">
                <div class="col-md-4">
                    <strong>Jalankan scanner di server target</strong>
                    <p class="tw-text-sm tw-text-gray-500 tw-mt-1">Gunakan terminal atau SSH dari root instalasi yang sama dengan halaman ini.</p>
                </div>
                <div class="col-md-8">
                    <code class="security-command">python3 scripts/security_audit.py --json --output=/tmp/security-audit.json</code>
                    <p class="tw-text-xs tw-text-gray-500 tw-mt-2">Salin JSON ke komputer lalu unggah lewat tombol <strong>Baca JSON</strong>. Root laporan harus cocok dengan instalasi aktif agar aksi cleanup tersedia.</p>
                </div>
            </div>
        </div>
    </div>

    @if(empty($report))
        <div class="security-card">
            <div class="security-empty">
                <div class="security-empty-icon"><i class="fas fa-shield-alt"></i></div>
                <h3 class="tw-font-bold tw-text-xl tw-text-gray-900">Belum ada laporan audit</h3>
                <p class="tw-text-gray-500 tw-mx-auto tw-mt-2" style="max-width:560px">Unggah laporan JSON yang dihasilkan scanner Python. Isi JSON diperlakukan sebagai data dan tidak pernah dieksekusi.</p>
                <button type="button" class="btn btn-primary security-btn tw-mt-3" data-toggle="modal" data-target="#upload-audit-modal">Pilih laporan JSON</button>
            </div>
        </div>
    @else
        <div class="row">
            <div class="col-lg-9">
                <div class="security-assessment tw-mb-5">
                    <strong>
                        <span class="security-risk security-risk-{{ $currentAssessment['risk'] }}"><span class="security-dot"></span> Risiko {{ $riskLabels[$currentAssessment['risk']] }}</span>
                    </strong>
                    @if($currentAssessment['risk'] === 'critical')
                        Bukti berkeyakinan tinggi menunjukkan file aktif yang dapat menjalankan kode ditemukan di area publik. Situasi harus diperlakukan sebagai kompromi server sampai pemulihan dari sumber bersih dan pemeriksaan log membuktikan sebaliknya.
                    @elseif($currentAssessment['risk'] === 'high')
                        Ada temuan berisiko tinggi di luar dependency. Review dan isolasi target sebelum aplikasi dianggap aman.
                    @elseif($currentAssessment['risk'] === 'medium')
                        Ada indikator yang perlu diverifikasi, tetapi laporan ini belum cukup untuk menyimpulkan kompromi aktif.
                    @else
                        Tidak ada indikator kuat pada ruang lingkup scan ini. Tetap perhatikan keterbatasan dan file yang dilewati.
                    @endif
                </div>
            </div>
            <div class="col-lg-3 tw-mb-5">
                <label class="tw-text-xs tw-font-bold tw-uppercase tw-text-gray-500">Laporan aktif</label>
                <select class="form-control" id="security-report-selector" style="border-radius:10px;height:42px">
                    @foreach($reports as $history)
                        <option value="{{ route('security-audit.index', ['report' => $history['id']]) }}" @selected($history['id'] === $report['id'])>
                            {{ \Illuminate\Support\Str::limit($history['original_name'], 35) }} — {{ $riskLabels[$history['risk']] ?? $history['risk'] }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="row">
            <div class="col-sm-6 col-lg-3"><div class="security-stat"><div class="security-stat-label">Temuan relevan</div><div class="security-stat-value">{{ number_format($currentAssessment['relevant_total'], 0, ',', '.') }}</div><div class="security-stat-note">di luar dependency vendor</div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="security-stat"><div class="security-stat-label">Kritis</div><div class="security-stat-value" style="color:#b91c1c">{{ number_format($currentAssessment['relevant_counts']['critical'], 0, ',', '.') }}</div><div class="security-stat-note">perlu respons segera</div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="security-stat"><div class="security-stat-label">Kandidat webshell</div><div class="security-stat-value" style="color:#c2410c">{{ number_format($currentAssessment['webshells'], 0, ',', '.') }}</div><div class="security-stat-note">executable di folder upload</div></div></div>
            <div class="col-sm-6 col-lg-3"><div class="security-stat"><div class="security-stat-label">Noise vendor</div><div class="security-stat-value" style="color:#4338ca">{{ number_format($currentAssessment['vendor_noise'], 0, ',', '.') }}</div><div class="security-stat-note">review lock/build, jangan auto-delete</div></div></div>
        </div>

        <div class="row">
            <div class="col-lg-7">
                <div class="security-card">
                    <div class="security-card-header"><h3>Penilaian dan tindakan yang disarankan</h3></div>
                    <div class="security-card-body">
                        @foreach($currentAssessment['recommendations'] as $recommendation)
                            <div class="security-rec">
                                <div class="security-rec-priority">{{ $recommendation['priority'] }}</div>
                                <div><h4>{{ $recommendation['title'] }}</h4><p>{{ $recommendation['detail'] }}</p></div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="security-card">
                    <div class="security-card-header"><h3>Ruang lingkup laporan</h3></div>
                    <div class="security-card-body">
                        <dl class="security-meta">
                            <dt>Dibuat</dt><dd>{{ $report['report']['generated_at'] ?: '—' }}</dd>
                            <dt>Rentang sejak</dt><dd>{{ $report['report']['since'] ?: '—' }}</dd>
                            <dt>File diperiksa</dt><dd>{{ number_format($report['report']['files_scanned'], 0, ',', '.') }}</dd>
                            <dt>Total mentah</dt><dd>{{ number_format($currentAssessment['total'], 0, ',', '.') }} temuan</dd>
                            <dt>Root laporan</dt><dd>{{ $report['report']['root'] }}</dd>
                            <dt>Sumber</dt><dd>{{ $report['source'] === 'live' ? 'Audit langsung' : 'Upload JSON' }}</dd>
                        </dl>
                        <a class="btn btn-default btn-sm tw-mt-4" href="{{ route('security-audit.download', ['report' => $report['id']]) }}"><i class="fas fa-download"></i> Unduh JSON asli</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="security-card">
            <div class="security-card-header">
                <h3>Detail temuan</h3>
                <p class="tw-text-xs tw-text-gray-500 tw-mt-1 tw-mb-0">Tabel memuat seluruh hasil. Label “noise vendor” berarti perlu verifikasi dependency, bukan izin untuk menghapus library secara otomatis.</p>
            </div>
            <div class="security-card-body">
                <div class="security-filters">
                    <select id="security-severity-filter" class="form-control input-sm">
                        <option value="">Semua tingkat</option><option value="Kritis">Kritis</option><option value="Tinggi">Tinggi</option><option value="Sedang">Sedang</option><option value="Rendah">Rendah</option>
                    </select>
                    <select id="security-scope-filter" class="form-control input-sm">
                        <option value="">Semua klasifikasi</option><option value="Artefak aktif">Artefak aktif</option><option value="Review manual">Review manual</option><option value="Noise vendor">Noise vendor</option>
                    </select>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped security-table" id="security-findings-table" style="width:100%">
                        <thead><tr><th>Tingkat</th><th>Klasifikasi</th><th>Jenis</th><th>Path</th><th>Waktu</th><th>Detail deteksi</th></tr></thead>
                        <tbody>
                        @foreach($report['report']['findings'] as $finding)
                            @php
                                $scope = $finding['_vendor_noise'] ? 'Noise vendor' : ($finding['_cleanup_eligible'] ? 'Artefak aktif' : 'Review manual');
                                $badge = $finding['severity'] === 'critical' ? 'danger' : ($finding['severity'] === 'high' ? 'warning' : ($finding['severity'] === 'medium' ? 'info' : 'muted'));
                            @endphp
                            <tr>
                                <td><span class="security-badge security-badge-{{ $badge }}">{{ $severityLabels[$finding['severity']] }}</span></td>
                                <td><span class="security-badge {{ $scope === 'Artefak aktif' ? 'security-badge-danger' : ($scope === 'Noise vendor' ? 'security-badge-muted' : 'security-badge-info') }}">{{ $scope }}</span></td>
                                <td>{{ str_replace('_', ' ', $finding['kind']) }}</td>
                                <td><span class="security-path">{{ $finding['path'] }}</span></td>
                                <td data-order="{{ $finding['mtime'] ?? 0 }}">{{ $finding['modified_at'] ?: '—' }}</td>
                                <td>
                                    <details><summary>Lihat alasan</summary><div class="detail-copy">
                                        @if(!empty($finding['signals']))<div><strong>Signal:</strong> {{ implode(', ', $finding['signals']) }}</div>@endif
                                        @foreach($finding['reasons'] as $reason)<div>• {{ $reason }}</div>@endforeach
                                        <div class="tw-mt-2"><strong>SHA-256:</strong> <span class="security-path">{{ $finding['sha256'] ?: 'tidak tersedia' }}</span></div>
                                        <div><strong>Ukuran:</strong> {{ is_null($finding['size']) ? '—' : number_format($finding['size'], 0, ',', '.').' byte' }}</div>
                                    </div></details>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-7">
                <div class="security-card">
                    <div class="security-card-header"><h3>Perubahan Git dan keterbatasan</h3></div>
                    <div class="security-card-body">
                        <details>
                            <summary class="tw-font-bold tw-cursor-pointer">{{ count($report['report']['git_changes']) }} perubahan working tree — buka daftar</summary>
                            <pre class="tw-mt-3" style="max-height:330px;overflow:auto;white-space:pre-wrap">{{ implode("\n", $report['report']['git_changes']) ?: 'Tidak ada perubahan Git yang dilaporkan.' }}</pre>
                        </details>
                        @if(!empty($report['report']['skipped']))
                            <details class="tw-mt-4"><summary class="tw-font-bold tw-cursor-pointer">{{ count($report['report']['skipped']) }} keterbatasan scan</summary><ul class="tw-mt-2">@foreach($report['report']['skipped'] as $item)<li>{{ $item }}</li>@endforeach</ul></details>
                        @endif
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="security-cleanup-box">
                    <h3><i class="fas fa-broom tw-mr-1"></i> Bersihkan artefak berbahaya</h3>
                    <p><strong>{{ $report['cleanup']['eligible_count'] }} file</strong> berkeyakinan tinggi eligible. File akan disalin dan diverifikasi ke karantina privat sebelum dihapus dari aplikasi aktif. File vendor, source, dan <code>.env</code> tidak ikut dihapus.</p>
                    @if(!$report['cleanup']['root_matches'])
                        <div class="security-root-warning tw-mb-3"><strong>Pembersihan dikunci.</strong><br>Root laporan berbeda dari instalasi yang sedang dibuka. Buka halaman ini pada server <span class="security-path">{{ $report['cleanup']['report_root'] }}</span>.</div>
                    @endif
                    <button type="button" class="btn btn-danger security-btn" data-toggle="modal" data-target="#cleanup-audit-modal" @disabled(!$report['cleanup']['can_cleanup'])>
                        Bersihkan Semua yang Eligible
                    </button>
                    @if(!empty($report['cleanup_runs']))
                        <div class="tw-mt-4 tw-pt-3 tw-border-t">
                            <strong>Riwayat pembersihan</strong>
                            @foreach(array_reverse($report['cleanup_runs']) as $run)
                                <div class="tw-text-xs tw-text-gray-500 tw-mt-2">{{ $run['executed_at'] }} — {{ $run['removed_count'] }} dibersihkan, {{ $run['skipped_count'] }} dilewati.</div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @if(!empty($reports))
        <div class="security-card tw-mt-5">
            <div class="security-card-header"><h3>Riwayat laporan</h3></div>
            <div class="security-card-body">
                @foreach(array_slice($reports, 0, 10) as $history)
                    <div class="security-history-row">
                        <div><div class="security-history-name">{{ $history['original_name'] }}</div><div class="security-history-meta">{{ $history['imported_at'] }} · {{ $history['relevant_total'] }} relevan dari {{ $history['total'] }} temuan · {{ $history['source'] === 'live' ? 'audit langsung' : 'upload' }}</div></div>
                        <a href="{{ route('security-audit.index', ['report' => $history['id']]) }}" class="btn btn-default btn-sm">Buka</a>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</section>

<div class="modal fade" id="upload-audit-modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form id="security-upload-form" enctype="multipart/form-data">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title tw-font-bold">Baca laporan audit JSON</h4></div>
            <div class="modal-body">
                <p class="tw-text-gray-500">File diperlakukan sebagai data dan divalidasi; tidak ada isi JSON yang dijalankan sebagai perintah.</p>
                <div class="form-group"><label>File JSON</label><input type="file" name="report" class="form-control" accept="application/json,.json" required><span class="help-block">Maksimal 15 MB, hasil dari <code>scripts/security_audit.py</code>.</span></div>
                <div class="alert alert-danger security-form-error" style="display:none"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Batal</button><button type="submit" class="btn btn-primary security-btn">Baca laporan</button></div>
        </form>
    </div></div>
</div>

@if(!empty($report))
<div class="modal fade" id="cleanup-audit-modal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form id="security-cleanup-form" data-url="{{ route('security-audit.cleanup', ['report' => $report['id']]) }}">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title tw-font-bold tw-text-red-700">Konfirmasi pembersihan</h4></div>
            <div class="modal-body">
                <div class="alert alert-warning"><strong>Tindakan sensitif.</strong> Sistem akan memverifikasi path dan hash, menyimpan salinan karantina privat, lalu menghapus file eligible dari lokasi aktif. File yang berubah akan dilewati.</div>
                <div class="form-group"><label>Ketik <code>BERSIHKAN SEMUA</code></label><input type="text" name="confirmation" class="form-control" autocomplete="off" required></div>
                <div class="form-group"><label>Password akun Anda</label><input type="password" name="password" class="form-control" autocomplete="current-password" required></div>
                <div class="alert alert-danger security-form-error" style="display:none"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Jangan bersihkan</button><button type="submit" class="btn btn-danger security-btn">Ya, Bersihkan Semua</button></div>
        </form>
    </div></div>
</div>
@endif
@endsection

@section('javascript')
<script src="{{ asset('js/security-audit.js?v=' . $asset_v) }}"></script>
@endsection
