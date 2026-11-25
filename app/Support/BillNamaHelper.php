<?php

namespace App\Support;

use App\Models\scctbill;
use Illuminate\Support\Collection;

class BillNamaHelper
{
    public static function bulanMap(): array
    {
        return [
            '01' => 'Januari',
            '02' => 'Februari',
            '03' => 'Maret',
            '04' => 'April',
            '05' => 'Mei',
            '06' => 'Juni',
            '07' => 'Juli',
            '08' => 'Agustus',
            '09' => 'September',
            '10' => 'Oktober',
            '11' => 'November',
            '12' => 'Desember',
        ];
    }

    public static function namaBulan(?string $bulanKode): ?string
    {
        $kode = str_pad(preg_replace('/\D/', '', (string) $bulanKode), 2, '0', STR_PAD_LEFT);

        return self::bulanMap()[$kode] ?? null;
    }

    public static function containsBulan(string $nama): bool
    {
        $upper = mb_strtoupper($nama);
        foreach (self::bulanMap() as $label) {
            if (str_contains($upper, mb_strtoupper($label))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Susun BILLNM: "SPP" + bulan → "SPP Mei". Tidak dobel jika bulan sudah ada di nama.
     */
    public static function compose(string $namaTagihan, ?string $bulanKode): string
    {
        $nama = trim($namaTagihan);
        if ($nama === '') {
            return '';
        }

        $bulanNama = self::namaBulan($bulanKode);
        if (!$bulanNama) {
            return $nama;
        }

        if (self::containsBulan($nama)) {
            return $nama;
        }

        return trim($nama . ' ' . $bulanNama);
    }

    /**
     * Opsi filter Nama Tagihan = BILLNM aktual di scctbill (mirip exact-match intra).
     * Return collection of objects with ->tagihan for existing blade compatibility.
     */
    public static function filterOptions(?string $sekolah = null): Collection
    {
        $query = scctbill::query()
            ->when($sekolah, function ($q) use ($sekolah) {
                $q->whereExists(function ($sub) use ($sekolah) {
                    $sub->selectRaw('1')
                        ->from('scctcust')
                        ->whereColumn('scctcust.CUSTID', 'scctbill.CUSTID')
                        ->where(function ($w) use ($sekolah) {
                            $w->where('scctcust.CODE02', $sekolah)
                                ->orWhere('scctcust.CODE02', 'like', '%' . $sekolah . '%');
                        });
                });
            })
            ->whereNotNull('BILLNM')
            ->where('BILLNM', '!=', '')
            ->select('BILLNM')
            ->distinct()
            ->orderBy('BILLNM');

        return $query->pluck('BILLNM')
            ->filter(fn ($v) => trim((string) $v) !== '')
            ->unique()
            ->values()
            ->map(fn ($billnm) => (object) ['tagihan' => (string) $billnm]);
    }

    /**
     * Perluas pilihan filter master (mis. "SPP") ke varian BILLNM yang mungkin tersimpan
     * (mis. "SPP Mei", "BULAN MEI") agar query IN tetap kena.
     */
    public static function expandFilterNames(array $selected): array
    {
        $out = [];
        foreach ($selected as $raw) {
            $name = trim((string) $raw);
            if ($name === '' || strtolower($name) === 'all') {
                continue;
            }
            $out[] = $name;

            if (self::containsBulan($name)) {
                // BULAN MEI ↔ SPP Mei
                $upper = mb_strtoupper($name);
                foreach (self::bulanMap() as $kode => $label) {
                    $labelUpper = mb_strtoupper($label);
                    if (!str_contains($upper, $labelUpper)) {
                        continue;
                    }
                    $out[] = self::compose('SPP', $kode);
                    $out[] = 'BULAN ' . $labelUpper;
                    $out[] = 'BULAN ' . $label;
                    $out[] = 'SPP ' . $label;
                    $out[] = 'SPP ' . $labelUpper;
                }
                continue;
            }

            foreach (array_keys(self::bulanMap()) as $kode) {
                $composed = self::compose($name, $kode);
                if ($composed !== '') {
                    $out[] = $composed;
                }
            }

            if (mb_strtoupper($name) === 'SPP') {
                foreach (self::bulanMap() as $label) {
                    $out[] = 'BULAN ' . mb_strtoupper($label);
                    $out[] = 'BULAN ' . $label;
                }
            }
        }

        return array_values(array_unique(array_filter($out, fn ($v) => trim((string) $v) !== '')));
    }
}
