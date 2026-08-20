<?php

namespace justinholtweb\sevvies\helpers;

/**
 * Money arithmetic, kept in one place so rounding is decided once.
 */
abstract class Money
{
    /**
     * Round to cents the way an invoice does. PHP_ROUND_HALF_UP matches what
     * both Commerce and sevDesk print.
     */
    public static function round(float $amount, int $precision = 2): float
    {
        return round($amount, $precision, PHP_ROUND_HALF_UP);
    }

    /**
     * Are two amounts the same money? Compared in whole cents so a float that
     * is one ULP off does not read as a bookkeeping discrepancy.
     */
    public static function same(float $a, float $b, float $tolerance = 0.005): bool
    {
        return abs($a - $b) < $tolerance;
    }

    /**
     * Net amount of a gross amount at the given rate.
     */
    public static function netOf(float $gross, float $taxRate): float
    {
        if ($taxRate <= 0) {
            return $gross;
        }

        return $gross / (1 + ($taxRate / 100));
    }

    /**
     * Gross amount of a net amount at the given rate.
     */
    public static function grossOf(float $net, float $taxRate): float
    {
        if ($taxRate <= 0) {
            return $net;
        }

        return $net * (1 + ($taxRate / 100));
    }

    /**
     * sevDesk sends numbers as strings often enough to be worth normalising.
     */
    public static function toFloat(mixed $value): float
    {
        if (is_float($value) || is_int($value)) {
            return (float)$value;
        }

        return (float)str_replace(',', '.', (string)$value);
    }
}
