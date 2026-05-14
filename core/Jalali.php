<?php
/**
 * تبدیل تاریخ میلادی به شمسی (الگوریتم JDN)
 */
class Jalali {
    private static array $pn = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];

    public static function toJalali(int $unix_timestamp): string {
        $gy = (int)date('Y', $unix_timestamp);
        $gm = (int)date('n', $unix_timestamp);
        $gd = (int)date('j', $unix_timestamp);
        $gh = (int)date('H', $unix_timestamp);
        $gi = (int)date('i', $unix_timestamp);

        [$jy, $jm, $jd] = self::gregorianToJalali($gy, $gm, $gd);

        return self::toPersianNum(sprintf('%04d/%02d/%02d', $jy, $jm, $jd))
             . ' ساعت '
             . self::toPersianNum(sprintf('%02d:%02d', $gh, $gi));
    }

    public static function daysUntil(int $future_unix): string {
        $diff = $future_unix - time();
        if ($diff <= 0) return 'منقضی شده';
        $days = (int)ceil($diff / 86400);
        if ($days < 1) return 'کمتر از یک روز';
        return self::toPersianNum((string)$days) . ' روز دیگر';
    }

    private static function toPersianNum(string $s): string {
        return str_replace(range('0', '9'), self::$pn, $s);
    }

    private static function gregorianToJalali(int $gy, int $gm, int $gd): array {
        $g_y = $gy - 1600;
        $g_m = $gm - 1;
        $g_d = $gd - 1;

        $g_day_no = 365 * $g_y
                  + (int)(($g_y + 3) / 4)
                  - (int)(($g_y + 99) / 100)
                  + (int)(($g_y + 399) / 400);

        $gmd = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        for ($i = 0; $i < $g_m; $i++) {
            $g_day_no += $gmd[$i];
        }
        if ($g_m > 1 && (($g_y % 4 === 0 && $g_y % 100 !== 0) || ($g_y % 400 === 0))) {
            $g_day_no++;
        }
        $g_day_no += $g_d;

        $j_day_no = $g_day_no - 79;
        $j_np     = (int)($j_day_no / 12053);
        $j_day_no = $j_day_no % 12053;

        $jy       = 979 + 33 * $j_np + 4 * (int)($j_day_no / 1461);
        $j_day_no = $j_day_no % 1461;

        if ($j_day_no >= 366) {
            $jy      += (int)(($j_day_no - 1) / 365);
            $j_day_no = ($j_day_no - 1) % 365;
        }

        $jmd = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
        $jm  = 1;
        for ($i = 0; $i < 11; $i++) {
            if ($j_day_no >= $jmd[$i]) {
                $j_day_no -= $jmd[$i];
                $jm++;
            } else {
                break;
            }
        }

        return [$jy, $jm, $j_day_no + 1];
    }
}
