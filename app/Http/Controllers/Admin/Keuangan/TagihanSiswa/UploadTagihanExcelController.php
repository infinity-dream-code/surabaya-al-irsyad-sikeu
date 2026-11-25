<?php

namespace App\Http\Controllers\Admin\Keuangan\TagihanSiswa;

use App\Http\Controllers\Controller;
use App\Imports\Keuangan\TagihanSiswa\ImportTagihanExcel;
use App\Imports\MasterData\ImportDataSiswa;
use App\Models\mst_kelas;
use App\Models\mst_tagihan;
use App\Models\mst_thn_aka;
use App\Models\u_akun;
use App\Models\scctbill;
use App\Models\scctbill_detail;
use App\Models\scctcust;
use App\Models\ValidationMessage;
use App\Support\BillNamaHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\HeadingRowImport;
use Maatwebsite\Excel\Validators\ValidationException;

class UploadTagihanExcelController extends Controller
{
    public string $title = 'Keuangan';
    public string $mainTitle = 'Tagihan Siswa';
    public string $dataTitle = 'Upload Tagihan Excel';
    public string $cacheKey = 'import_tagihan_excel';

    public function index()
    {
        $data['title'] = $this->title;
        $data['mainTitle'] = $this->mainTitle;
        $data['dataTitle'] = $this->dataTitle;
        $data['columnsUrl'] = route('admin.keuangan.tagihan-siswa.upload-tagihan-excel.get-column');
        $data['datasUrl'] = route('admin.keuangan.tagihan-siswa.upload-tagihan-excel.get-data');

        $data['thn_aka'] = mst_thn_aka::orderBy('thn_aka', 'desc')->get();
        $data['post'] = u_akun::orderBy('KodeAkun', 'asc')->get();
        $data['tagihan'] = mst_tagihan::orderBy('urut', 'asc')->get();

        return view('admin.keuangan.tagihan_siswa.upload_tagihan_excel.index', $data);
    }

    public function getColumn()
    {
        return [
            ['data' => null, 'name' => 'no', 'className' => 'text-center', 'columnType' => 'row'],
            ['data' => 'nis', 'name' => 'NIS', 'searchable' => true, 'orderable' => true],
            ['data' => 'name', 'name' => 'NAMA', 'searchable' => true, 'orderable' => true],
            ['data' => 'status', 'name' => 'Status', 'searchable' => true, 'orderable' => true, 'columnType' => 'importstatus'],
            ['data' => 'keterangan', 'name' => 'Keterangan', 'searchable' => true, 'orderable' => true],
            ['data' => 'sekolah', 'name' => 'Sekolah', 'searchable' => true, 'orderable' => true],
            ['data' => 'kelas', 'name' => 'Kelas', 'searchable' => true, 'orderable' => true],
            ['data' => 'kelompok', 'name' => 'Kelompok', 'searchable' => true, 'orderable' => true],
            ['data' => 'nominal', 'name' => 'Nominal', 'searchable' => true, 'orderable' => true, 'columnType' => 'currency'],
        ];
    }

    public function getData(Request $request)
    {
        $draw = (int) $request->get('draw', 1);
        $start = max(0, (int) $request->get('start', 0));
        $rowperpage = (int) $request->get('length', 10);
        if ($rowperpage < 1) {
            $rowperpage = 10;
        }

        $searchValue = trim((string) data_get($request->get('search'), 'value', ''));
        $cachedData = collect(Cache::get($this->cacheKey, []));

        if ($searchValue !== '') {
            $cachedData = $cachedData->filter(function ($item) use ($searchValue) {
                $nis = (string) ($item['nis'] ?? '');
                $ket = (string) ($item['keterangan'] ?? '');
                return str_contains($nis, $searchValue) || str_contains(strtolower($ket), strtolower($searchValue));
            })->values();
        }

        $total = $cachedData->count();
        $page = $cachedData->slice($start, $rowperpage)->values();

        $select = [
            'scctcust.NMCUST',
            'scctcust.NOCUST',
            'scctcust.NUM2ND',
            'scctcust.CODE02',
            'scctcust.DESC02',
            'scctcust.DESC03',
            'scctcust.DESC04',
        ];

        $nisList = $page->pluck('nis')->filter()->unique()->values()->all();
        $siswaMap = collect();
        if (!empty($nisList)) {
            try {
                $siswaMap = scctcust::select($select)
                    ->whereIn('scctcust.NOCUST', $nisList)
                    ->get()
                    ->keyBy(fn ($s) => (string) $s->NOCUST);
            } catch (\Throwable $e) {
                \Log::warning('upload-tagihan-excel.get-data.siswa', ['error' => $e->getMessage()]);
            }
        }

        $records = $page->map(function ($item) use ($siswaMap) {
            $nis = (string) ($item['nis'] ?? '');
            $siswa = $siswaMap->get($nis);

            return [
                'nis' => $nis,
                'name' => $siswa->NMCUST ?? null,
                'ortu' => $item['ayah'] ?? null,
                'sekolah' => $siswa->CODE02 ?? null,
                'kelas' => $siswa->DESC02 ?? null,
                'kelompok' => $siswa->DESC03 ?? null,
                'nominal' => $item['nominal'] ?? null,
                'status' => $item['status'] ?? 0,
                'keterangan' => $item['keterangan'] ?? null,
            ];
        })->all();

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $total,
            'data' => $records,
        ]);
    }


    public function store(Request $request)
    {
        $request->validate(
            [
                'fileImport' => ['required', 'mimes:xls,xlsx', 'max:1024']
            ],
            ValidationMessage::messages(),
            ValidationMessage::attributes()
        );

        $file = $request->fileImport;

        try {
            $headingsData = (new HeadingRowImport)->toArray($file);
            $requiredColumns = ['nis', 'nominal'];
            if (empty($headingsData) || !isset($headingsData[0][0])) throw new \Exception ('Tidak dapat membaca judul kolom dari file. Pastikan file memiliki header yang sesuai.');
            $headings = $headingsData[0][0];
            $headings = array_map('strtolower', $headings);
            $missingColumns = [];
            foreach ($requiredColumns as $column) if (!in_array($column, $headings)) $missingColumns[] = $column;

            if (!empty($missingColumns)) {
                $formattedMissingColumns = strtoupper(str_replace('_', ' ', implode(', ', $missingColumns)));
                $formattedRequiredColumns = strtoupper(str_replace('_', ' ', implode(', ', $requiredColumns)));
                throw new \Exception("Kolom $formattedMissingColumns tidak ditemukan.<br><hr> pastikan kolom berikut ada dan terisi pada file import yang akan diproses: $formattedRequiredColumns.",);
            }

            DB::beginTransaction();
            Excel::import(new ImportTagihanExcel(), $file);
            DB::commit();

            $data = Cache::get($this->cacheKey);
            return response()->json(['message' => 'Sukses, data tagihan telah diimport, silahkan periksa kembali', 'data' => $data], 200);
        } catch (ValidationException $e) {
            $errorMessages = $e->errors();
            $errorMessage = $errorMessages['error'][0] ?? 'Terjadi kesalahan saat melakukan import data.';
            return response()->json(['message' => $errorMessage, 'error' => $errorMessages], 422);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            return response()->json(['message' => "Gagal!<br> tidak dapat melakukan $this->mainTitle.<hr> $error", 'error' => $error], 422);
        }
    }

    public function validateExcel(Request $request)
    {
        $request->validate([
            'tahun_pelajaran' => ['required', 'regex:/^\d{4}\/\d{4}(?:\s*-\s*(GANJIL|GENAP))?$/'],
            'fungsi' => ['required', 'integer'],
            'tagihan' => ['required'],
            'post' => ['required', 'array', 'min:1'],
            'post.*' => ['required', 'string'],
        ], ValidationMessage::messages(), ValidationMessage::attributes());

        $data = Cache::get($this->cacheKey);
        if (empty($data))return response()->json(['message' => 'Silahkan import data tagihan terlebih dahulu'], 422);

        $tahun_akademik = mst_thn_aka::where('thn_aka', $request->tahun_pelajaran)->value('thn_aka');

        if (!$tahun_akademik || !preg_match('/\d{4}\/\d{4}/', $tahun_akademik, $matches)) {
            return response()->json(['message' => 'Tahun akademik tidak valid'], 422);
        }

        $tahun = substr($request->fungsi, 0, 4);
        $bulan = substr($request->fungsi, 4, 2) ?: date('m');

        $tagihan = mst_tagihan::where('urut', $request->tagihan)->first();
        if (!$tagihan) return response()->json(['message' => 'Tagihan tidak ditemukan, silahkan muat ulang halaman!'], 422);

        // Sama seperti Buat Tagihan: mis. "SPP Mei"
        $billNm = BillNamaHelper::compose((string) ($tagihan->tagihan ?? 'SPP'), $bulan);
        if ($billNm === '') {
            return response()->json(['message' => 'Nama tagihan tidak valid'], 422);
        }

        $postCodes = array_values(array_unique(array_filter((array) $request->post)));
        $posts = u_akun::whereIn('KodeAkun', $postCodes)->get();
        if ($posts->count() !== count($postCodes)) {
            return response()->json(['message' => 'Post tidak ditemukan, silahkan muat ulang halaman!'], 422);
        }
        try {
            DB::beginTransaction();
            foreach ($data as $item) {
                if ($item['status'] != 1) continue;
                $siswa = scctcust::where('NOCUST', $item['nis'])->first();
                if (!$siswa) return response()->json(['message' => "siswa dengan nis: {$item['nis']} tidak ditemukan!"], 422);

                $tagihanSiswaTerbaru = scctbill::where('CUSTID', $siswa->CUSTID)
                    ->orderBy('FUrutan', 'DESC')
                    ->first();

                $urut = $tagihanSiswaTerbaru ? $tagihanSiswaTerbaru['FUrutan'] + 1 : 1;
                $billCD = date('Y') . '/i' . date('m') . '-' . ($urut + 1);
                $nominal = (int) $item['nominal'];
                $totalNominal = $nominal * $posts->count();

                $bill = scctbill::create([
                    'CUSTID' => $siswa->CUSTID,
                    'BILLAC' => $tahun . $bulan,
                    'BILLNM' => $billNm,
                    'BILLAM' => $totalNominal,
                    'PAIDST' => 0,
                    'FUrutan' => $urut,
                    'FTGLTagihan' => now(),
                    'FSTSBolehBayar' => 1,
                    'BTA' => $matches[0],
                    'BILLCD' => $billCD,
                ]);

                foreach ($posts as $post) {
                    scctbill_detail::create([
                        'KodePost' => $post->KodeAkun,
                        'CUSTID' => $bill->CUSTID,
                        'BILLAM' => $nominal,
                        'tahun' => $tahun,
                        'periode' => $bulan,
                        'BILLCD' => $bill->BILLCD,
                    ]);
                }
            }

            Cache::forget($this->cacheKey);

            DB::commit();
            return response()->json(['message' => "Data tagihan disimpan!",], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => "Terjadi kesalahan saat menyimpan data, silahkan muat ulang halaman!", 'error' => $e], 422);
        }
    }
}
