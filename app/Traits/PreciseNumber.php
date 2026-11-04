<?php


namespace App\Traits;


trait PreciseNumber
{
   protected static int $scale = 20;


   protected static function normalize($value): string
   {
       if (is_string($value)) {
           return $value;
       }


       if (is_int($value)) {
           return (string) $value;
       }


       return number_format((float)$value, self::$scale, '.', '');
   }


   // Pure PHP arbitrary-precision helpers (no BCMath extension required)


   private static function mathAdd(string $a, string $b, int $scale): string
   {
       [$a, $b] = self::alignDecimals($a, $b);
       $dotPos = strpos($a, '.');
       $decLen = $dotPos !== false ? strlen($a) - $dotPos - 1 : 0;


       $aDigits = str_replace('.', '', $a);
       $bDigits = str_replace('.', '', $b);
       $result  = self::addStrings($aDigits, $bDigits);


       return self::formatResult($result, $decLen, $scale);
   }


   private static function mathSub(string $a, string $b, int $scale): string
   {
       $negA = strpos($a, '-') === 0;
       $negB = strpos($b, '-') === 0;


       if ($negA && !$negB) {
           return '-' . self::mathAdd(ltrim($a, '-'), $b, $scale);
       }
       if (!$negA && $negB) {
           return self::mathAdd($a, ltrim($b, '-'), $scale);
       }
       if ($negA && $negB) {
           return self::mathSub(ltrim($b, '-'), ltrim($a, '-'), $scale);
       }


       [$a, $b] = self::alignDecimals($a, $b);
       $dotPos = strpos($a, '.');
       $decLen = $dotPos !== false ? strlen($a) - $dotPos - 1 : 0;


       $aDigits = str_replace('.', '', $a);
       $bDigits = str_replace('.', '', $b);


       $cmp = self::compareStrings($aDigits, $bDigits);
       if ($cmp === 0) {
           return number_format(0, $scale, '.', '');
       }


       $negative = $cmp < 0;
       if ($negative) {
           [$aDigits, $bDigits] = [$bDigits, $aDigits];
       }


       $result = self::subStrings($aDigits, $bDigits);
       $formatted = self::formatResult($result, $decLen, $scale);


       return $negative ? '-' . $formatted : $formatted;
   }


   private static function mathMul(string $a, string $b, int $scale): string
   {
       $negA = strpos($a, '-') === 0;
       $negB = strpos($b, '-') === 0;
       $negative = $negA xor $negB;


       $a = ltrim($a, '-');
       $b = ltrim($b, '-');


       $dotA = strpos($a, '.');
       $dotB = strpos($b, '.');
       $decA = $dotA !== false ? strlen($a) - $dotA - 1 : 0;
       $decB = $dotB !== false ? strlen($b) - $dotB - 1 : 0;
       $totalDec = $decA + $decB;


       $aDigits = str_replace('.', '', $a);
       $bDigits = str_replace('.', '', $b);


       $result = self::mulStrings($aDigits, $bDigits);
       $formatted = self::formatResult($result, $totalDec, $scale);


       return $negative ? '-' . $formatted : $formatted;
   }


   private static function mathDiv(string $a, string $b, int $scale): string
   {
       $negA = strpos($a, '-') === 0;
       $negB = strpos($b, '-') === 0;
       $negative = $negA xor $negB;


       $a = ltrim($a, '-');
       $b = ltrim($b, '-');


       // Shift both to integers by aligning decimals
       $dotA = strpos($a, '.');
       $dotB = strpos($b, '.');
       $decA = $dotA !== false ? strlen($a) - $dotA - 1 : 0;
       $decB = $dotB !== false ? strlen($b) - $dotB - 1 : 0;


       $aDigits = str_replace('.', '', $a);
       $bDigits = str_replace('.', '', $b);


       // Normalize: remove leading zeros
       $aDigits = ltrim($aDigits, '0') ?: '0';
       $bDigits = ltrim($bDigits, '0') ?: '0';


       // Long division: compute $scale + extra digits then truncate
       $extra   = $scale + 4;
       $decDiff = $decA - $decB;


       // Append zeros to dividend to get desired precision
       $aDigits .= str_repeat('0', $extra);


       [$quotient] = self::divStrings($aDigits, $bDigits);


       // Adjust decimal position: decDiff shifts the decimal point
       $decPos = $extra - $decDiff;


       $formatted = self::formatResult($quotient, $decPos, $scale);


       return $negative ? '-' . $formatted : $formatted;
   }


   private static function mathComp(string $a, string $b): int
   {
       [$a, $b] = self::alignDecimals($a, $b);


       $negA = strpos($a, '-') === 0;
       $negB = strpos($b, '-') === 0;


       if ($negA && !$negB) return -1;
       if (!$negA && $negB) return 1;


       $aDigits = str_replace(['.', '-'], '', $a);
       $bDigits = str_replace(['.', '-'], '', $b);
       $cmp = self::compareStrings($aDigits, $bDigits);


       return $negA ? -$cmp : $cmp;
   }


   private static function mathPow(string $base, int $exp): string
   {
       $result = '1';
       for ($i = 0; $i < $exp; $i++) {
           $result = self::mathMul($result, $base, 0);
           // Strip trailing decimal zeros from intermediate results
           if (strpos($result, '.') !== false) {
               $result = rtrim(rtrim($result, '0'), '.');
           }
       }
       return $result;
   }


   // String arithmetic primitives


   private static function alignDecimals(string $a, string $b): array
   {
       $dotA = strpos($a, '.');
       $dotB = strpos($b, '.');
       $decA = $dotA !== false ? strlen($a) - $dotA - 1 : 0;
       $decB = $dotB !== false ? strlen($b) - $dotB - 1 : 0;


       if ($decA === 0 && $decB === 0) {
           return [$a, $b];
       }


       if ($dotA === false) $a .= '.';
       if ($dotB === false) $b .= '.';


       $maxDec = max($decA, $decB);
       $a = str_pad($a, strlen($a) + ($maxDec - $decA), '0');
       $b = str_pad($b, strlen($b) + ($maxDec - $decB), '0');


       return [$a, $b];
   }


   private static function addStrings(string $a, string $b): string
   {
       $a = ltrim($a, '0') ?: '0';
       $b = ltrim($b, '0') ?: '0';


       $maxLen = max(strlen($a), strlen($b));
       $a = str_pad($a, $maxLen, '0', STR_PAD_LEFT);
       $b = str_pad($b, $maxLen, '0', STR_PAD_LEFT);


       $carry  = 0;
       $result = '';


       for ($i = $maxLen - 1; $i >= 0; $i--) {
           $sum    = (int)$a[$i] + (int)$b[$i] + $carry;
           $carry  = intdiv($sum, 10);
           $result = ($sum % 10) . $result;
       }


       return $carry ? $carry . $result : $result;
   }


   private static function subStrings(string $a, string $b): string
   {
       // Assumes $a >= $b (both non-negative digit strings, no dots)
       $maxLen = max(strlen($a), strlen($b));
       $a = str_pad($a, $maxLen, '0', STR_PAD_LEFT);
       $b = str_pad($b, $maxLen, '0', STR_PAD_LEFT);


       $borrow = 0;
       $result = '';


       for ($i = $maxLen - 1; $i >= 0; $i--) {
           $diff   = (int)$a[$i] - (int)$b[$i] - $borrow;
           $borrow = $diff < 0 ? 1 : 0;
           $result = (($diff + 10) % 10) . $result;
       }


       return ltrim($result, '0') ?: '0';
   }


   private static function mulStrings(string $a, string $b): string
   {
       $a = ltrim($a, '0') ?: '0';
       $b = ltrim($b, '0') ?: '0';


       if ($a === '0' || $b === '0') return '0';


       $lenA   = strlen($a);
       $lenB   = strlen($b);
       $digits = array_fill(0, $lenA + $lenB, 0);


       for ($i = $lenA - 1; $i >= 0; $i--) {
           for ($j = $lenB - 1; $j >= 0; $j--) {
               $mul   = (int)$a[$i] * (int)$b[$j];
               $p1    = $i + $j;
               $p2    = $i + $j + 1;
               $sum   = $mul + $digits[$p2];
               $digits[$p2] = $sum % 10;
               $digits[$p1] += intdiv($sum, 10);
           }
       }


       return ltrim(implode('', $digits), '0') ?: '0';
   }


   private static function divStrings(string $a, string $b): array
   {
       // Integer long division; returns [quotient, remainder] as strings
       $a = ltrim($a, '0') ?: '0';
       $b = ltrim($b, '0') ?: '0';


       if ($b === '0') return ['0', '0'];
       if (self::compareStrings($a, $b) < 0) return ['0', $a];


       $quotient  = '';
       $remainder = '0';


       for ($i = 0; $i < strlen($a); $i++) {
           $remainder = ltrim($remainder . $a[$i], '0') ?: '0';
           $q = 0;
           while (self::compareStrings($remainder, $b) >= 0) {
               $remainder = self::subStrings($remainder, $b);
               $q++;
           }
           $quotient .= $q;
       }


       return [ltrim($quotient, '0') ?: '0', $remainder];
   }


   private static function compareStrings(string $a, string $b): int
   {
       $a = ltrim($a, '0') ?: '0';
       $b = ltrim($b, '0') ?: '0';


       if (strlen($a) !== strlen($b)) {
           return strlen($a) <=> strlen($b);
       }


       return strcmp($a, $b) <=> 0;
   }


   private static function formatResult(string $digits, int $decimalPlaces, int $scale): string
   {
       $digits = ltrim($digits, '0') ?: '0';


       if ($decimalPlaces <= 0) {
           $intPart = $decimalPlaces < 0
               ? $digits . str_repeat('0', -$decimalPlaces)
               : $digits;
           return $scale > 0 ? $intPart . '.' . str_repeat('0', $scale) : $intPart;
       }


       if (strlen($digits) <= $decimalPlaces) {
           $digits = str_pad($digits, $decimalPlaces + 1, '0', STR_PAD_LEFT);
       }


       $intPart = substr($digits, 0, strlen($digits) - $decimalPlaces);
       $decPart = substr($digits, strlen($digits) - $decimalPlaces);


       $intPart = ltrim($intPart, '0') ?: '0';


       if ($scale >= strlen($decPart)) {
           $decPart = str_pad($decPart, $scale, '0');
       } else {
           $decPart = substr($decPart, 0, $scale);
       }


       return $scale > 0 ? $intPart . '.' . $decPart : $intPart;
   }


   // Public API — identical signatures to the original


   public static function add(...$values): string
   {
       $result = '0';


       foreach ($values as $value) {
           $result = self::mathAdd($result, self::normalize($value), self::$scale);
       }


       return $result;
   }


   public static function sub($first, ...$rest): string
   {
       $result = self::normalize($first);


       foreach ($rest as $value) {
           $result = self::mathSub($result, self::normalize($value), self::$scale);
       }


       return $result;
   }


   public static function mul(...$values): string
   {
       if (empty($values)) {
           return '0';
       }


       $result = '1';


       foreach ($values as $value) {
           $result = self::mathMul($result, self::normalize($value), self::$scale);
       }


       return $result;
   }


   public static function div($first, ...$rest): string
   {
       $result = self::normalize($first);


       foreach ($rest as $value) {
           $value = self::normalize($value);


           if (self::mathComp($value, '0') === 0) {
               return '0';
           }


           $result = self::mathDiv($result, $value, self::$scale);
       }


       return $result;
   }


   public static function compare($a, $b): int
   {
       return self::mathComp(self::normalize($a), self::normalize($b));
   }


   public static function round($value, int $precision = 2): string
   {
       $value = self::normalize($value);


       $factor = self::mathPow('10', $precision + 1);
       $tmp    = self::mathMul($value, $factor, 0);


       // Strip decimal part for digit inspection
       $dotPos = strpos($tmp, '.');
       $tmp    = $dotPos !== false ? substr($tmp, 0, $dotPos) : $tmp;


       $lastDigit = (int) substr($tmp, -1);
       $tmp       = substr($tmp, 0, -1) ?: '0';


       if ($lastDigit >= 5) {
           $tmp = self::addStrings($tmp, '1');
       }


       $divisor = self::mathPow('10', $precision);


       return self::mathDiv($tmp, $divisor, $precision);
   }
}



