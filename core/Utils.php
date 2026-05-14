<?php
class Utils {

    public static function generateUUIDv4(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function toEnglishDigits(string $str): string {
        $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        $arabic   = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $english  = ['0','1','2','3','4','5','6','7','8','9'];
        return str_replace(
            array_merge($persian, $arabic),
            array_merge($english, $english),
            $str
        );
    }

    public static function formatBytes(int $bytes, int $precision = 2): string {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow   = min((int)floor(log($bytes, 1024)), count($units) - 1);
        return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
    }

    public static function onlyDigits(string $input): string {
        return preg_replace('/\D/', '', self::toEnglishDigits($input));
    }

    public static function generateAuthority(string $prefix, int $user_id): string {
        return $prefix . '_' . $user_id . '_' . time();
    }
}
