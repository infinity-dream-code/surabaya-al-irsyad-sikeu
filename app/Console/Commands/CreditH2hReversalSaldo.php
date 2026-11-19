<?php

namespace App\Console\Commands;

use App\Models\scctbill;
use App\Models\scctcust;
use App\Models\sccttran;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreditH2hReversalSaldo extends Command
{
    protected $signature = 'sikeu:credit-h2h-reversal
                            {--nocust= : NIS siswa}
                            {--aa= : AA tagihan (opsional)}
                            {--include-null-fid : Sertakan tagihan FIDBANK kosong (reversal lama)}
                            {--dry-run : Tampilkan tanpa menyimpan}';

    protected $description = 'Kredit saldo VA untuk tagihan H2H yang sudah di-reversal tapi belum masuk sccttran';

    public function handle(): int
    {
        $nocust = trim((string) $this->option('nocust'));
        $aa = $this->option('aa');
        $dryRun = (bool) $this->option('dry-run');

        if ($nocust === '' && blank($aa)) {
            $this->error('Wajib --nocust= atau --aa=');
            return self::FAILURE;
        }

        $h2h = ['1', '2', '3', '4', '5', '6'];
        $query = scctbill::query()->where('PAIDST', 0);

        if (!blank($aa)) {
            $query->where('AA', $aa);
        } else {
            $siswa = scctcust::where('NOCUST', $nocust)->first();
            if (!$siswa) {
                $this->error("Siswa NIS {$nocust} tidak ditemukan");
                return self::FAILURE;
            }
            $query->where('CUSTID', $siswa->CUSTID);
        }

        // Prioritas: masih ada FIDBANK H2H. Fallback: FIDBANK kosong tapi ada jejak bayar H2H di sccttran (KREDIT bank/DEBET).
        $bills = $query->get();
        $credited = 0;

        foreach ($bills as $bill) {
            $fid = (string) ($bill->FIDBANK ?? '');
            $noreff = $bill->BILLCD ?: ('REV-' . $bill->AA);
            $already = sccttran::where('CUSTID', $bill->CUSTID)
                ->where('NOREFF', $noreff)
                ->where(function ($q) {
                    $q->where('METODE', 'REVERSAL H2H')
                        ->orWhere(function ($q2) {
                            $q2->where('METODE', 'JURNAL SALDO')->where('KREDIT', '>', 0);
                        });
                })
                ->where('KREDIT', '>', 0)
                ->exists();

            if ($already || (int) $bill->BILLAM <= 0) {
                continue;
            }

            $isH2h = in_array($fid, $h2h, true);
            $legacyNullFid = ($fid === '' || $fid === '0')
                && ($this->option('include-null-fid') || !blank($aa));

            if (!$isH2h && !$legacyNullFid) {
                continue;
            }

            $this->line("AA={$bill->AA} BILLNM={$bill->BILLNM} BILLAM={$bill->BILLAM} FIDBANK={$fid} → KREDIT saldo");

            if (!$dryRun) {
                DB::connection('DATA_MYSQL')->transaction(function () use ($bill, $noreff) {
                    sccttran::create([
                        'CUSTID' => $bill->CUSTID,
                        'NOREFF' => $noreff,
                        'METODE' => 'REVERSAL H2H',
                        'FIDBANK' => '1140002',
                        'KDCHANNEL' => 11,
                        'TRXDATE' => now()->format('Y-m-d H:i:s'),
                        'DEBET' => 0,
                        'KREDIT' => (int) $bill->BILLAM,
                        'TRANSNO' => 'repair-h2h-reversal',
                    ]);
                });
            }
            $credited++;
        }

        $this->info(($dryRun ? '[dry-run] ' : '') . "Selesai. {$credited} transaksi kredit.");
        return self::SUCCESS;
    }
}
