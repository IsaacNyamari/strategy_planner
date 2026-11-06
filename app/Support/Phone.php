<?php

namespace App\Support;

/**
 * Phone number helpers. Numbers are stored in international format
 * (e.g. +254712345678) so call / SMS / WhatsApp links always work.
 */
class Phone
{
    public static function countryCode(): string
    {
        return ltrim((string) config('worship.phone_country_code', '254'), '+');
    }

    /**
     * "0712 345 678", "712345678", "254712345678", "+254 712-345-678" => "+254712345678".
     * Returns null when it can't be a valid number.
     */
    public static function normalize(?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        $hasPlus = str_starts_with(trim($input), '+');
        $digits = preg_replace('/\D+/', '', $input) ?? '';
        $cc = static::countryCode();

        if ($hasPlus) {
            $number = $digits;
        } elseif (str_starts_with($digits, '00')) {
            $number = substr($digits, 2);
        } elseif (str_starts_with($digits, $cc)) {
            $number = $digits;
        } elseif (str_starts_with($digits, '0')) {
            $number = $cc.substr($digits, 1); // local format 07.. / 01..
        } elseif (strlen($digits) === 9) {
            $number = $cc.$digits; // 712345678
        } else {
            $number = $digits;
        }

        // E.164: up to 15 digits; local numbers here are 9 digits after the country code.
        return preg_match('/^[1-9]\d{8,14}$/', $number) ? '+'.$number : null;
    }

    /** "+254712345678" => "+254 712 345 678" for display. */
    public static function format(?string $phone): string
    {
        if (! $phone) {
            return '';
        }

        $cc = static::countryCode();

        if (str_starts_with($phone, '+'.$cc) && strlen($phone) === strlen($cc) + 10) {
            $local = substr($phone, strlen($cc) + 1);

            return '+'.$cc.' '.substr($local, 0, 3).' '.substr($local, 3, 3).' '.substr($local, 6);
        }

        return $phone;
    }

    public static function telUrl(string $phone): string
    {
        return 'tel:'.$phone;
    }

    public static function smsUrl(string $phone, ?string $text = null): string
    {
        return 'sms:'.$phone.($text ? '?body='.rawurlencode($text) : '');
    }

    public static function whatsappUrl(string $phone, ?string $text = null): string
    {
        return 'https://wa.me/'.ltrim($phone, '+').($text ? '?text='.rawurlencode($text) : '');
    }
}
