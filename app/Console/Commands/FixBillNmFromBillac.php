<?php

namespace App\Console\Commands;

use App\Models\scctbill;
use App\Models\scctbill_detail;
use App\Support\BillNamaHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixBillNmFromBillac extends Command
{
    protected $signature = 'sikeu:fix-billnm
                            {--nocust= : Batasi ke NIS tertentu}
                            {--dry-run : Tampilkan perubahan tanpa menyimpan}
                            {--force : Perbaiki semua BILLNM tanpa nama bulan (pakai BILLAC)}
                            {--base=SPP : Prefix nama tagihan}';

    protected $description = 'Perbaiki BILLNM yang tersimpan sebagai nama akun detail (tanpa bulan) menjadi base + bulan dari BILLAC';

    public function handle(): int
    {
        $nocust = $this->option('nocust');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $base = trim((string) $this->option('base')) ?: 'SPP';

        $query = scctbill::query()
            ->from('scctbill')
            ->when($nocust, function ($q) use ($nocust) {
                $q->join('scctcust', 'scctcust.CUSTID', '=', 'scctbill.CUSTID')
                    ->where('scctcust.NOCUST', $nocust)
                    ->select('scctbill.*');
            });

        $bills = $query->get();
        $updated = 0;

        foreach ($bills as $bill) {
            $billnm = trim((string) ($bill->BILLNM ?? ''));
            $billac = preg_replace('/\D/', '', (string) ($bill->BILLAC ?? ''));

            if ($billnm === '' || strlen($billac) !== 6 || BillNamaHelper::containsBulan($billnm)) {
                continue;
            }

            if (!$force) {
                // Hanya perbaiki jika BILLNM sama dengan nama akun di detail tagihan
                $detailQuery = scctbill_detail::query()
                    ->leftJoin('u_akun', 'u_akun.KodeAkun', '=', 'scctbill_detail.KodePost')
                    ->where('scctbill_detail.CUSTID', $bill->CUSTID);

                if (!blank($bill->BILLCD)) {
                    $detailQuery->where('scctbill_detail.BILLCD', $bill->BILLCD);
                }

                $detailNames = $detailQuery
                    ->pluck('u_akun.NamaAkun')
                    ->filter()
                    ->map(fn ($n) => mb_strtoupper(trim((string) $n)))
                    ->all();

                if ($detailNames === [] || !in_array(mb_strtoupper($billnm), $detailNames, true)) {
                    continue;
                }
            }

            $fixed = BillNamaHelper::compose($base, substr($billac, 4, 2));
            if ($fixed === '' || strcasecmp($fixed, $billnm) === 0) {
                continue;
            }

            $this->line("AA={$bill->AA} CUSTID={$bill->CUSTID} BILLAC={$bill->BILLAC}: \"{$billnm}\" → \"{$fixed}\"");

            if (!$dryRun) {
                scctbill::where('AA', $bill->AA)->update(['BILLNM' => $fixed]);
            }
            $updated++;
        }

        $this->info(($dryRun ? '[dry-run] ' : '') . "Selesai. {$updated} baris diperbaiki.");

        return self::SUCCESS;
    }
}
