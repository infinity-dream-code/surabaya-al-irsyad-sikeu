<?php

namespace App\Support;

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
     * Susun BILLNM: "SPP" + bulan → "SPP November". Tidak dobel jika bulan sudah ada di nama.
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
}
