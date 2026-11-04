<?php

namespace App\Services;

use Carbon\Carbon;

class DashboardService
{
    public function getDateTypeData(string $dateType): array
    {
        $from = null;
        $to = null;
        $type = null;
        $range = null;
        $keyRange = null;
        if ($dateType == 'yearEarn') {
            $from = Carbon::now()->startOfYear()->format('Y-m-d');
            $to = Carbon::now()->endOfYear()->format('Y-m-d');
            $range = range(1, 12);
            $type = 'month';
            $keyRange = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
        } elseif ($dateType == 'MonthEarn') {
            $from = date('Y-m-01');
            $to = date('Y-m-t 23:59:59');
            $endRange = date('d', strtotime($to));
            $range = range(1, $endRange);
            $type = 'day';
            $keyRange = $range;
        } elseif ($dateType == 'WeekEarn') {
            $from = Carbon::now()->startOfWeek(Carbon::SUNDAY)->format('Y-m-d');
            $to = Carbon::now()->endOfWeek(Carbon::SATURDAY)->format('Y-m-d');
            $range = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $type = 'day_of_week';
            $keyRange = $range;
        } else {
            // Anything else — including an absent type — reads as the year, which is what every
            // caller opens on. This used to fall through with all five values null, and the callers
            // type their arguments, so an unrecognised type produced a TypeError and a 500 instead
            // of a report.
            $from = Carbon::now()->startOfYear()->format('Y-m-d');
            $to = Carbon::now()->endOfYear()->format('Y-m-d');
            $range = range(1, 12);
            $type = 'month';
            $keyRange = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
        }
        return [
            'from' => $from,
            'to' => $to,
            'range' => $range,
            'type' => $type,
            'keyRange' => $keyRange
        ];
    }

    public function getDateWiseAmount(array $range, string $type, object|array $amountArray): array
    {
        $dateWiseAmount = [];
        foreach ($range as $value) {
            $dateWiseAmount[$value] = usdToDefaultCurrency(amount: 0);
        }
        foreach ($range as $value) {
            if (count($amountArray) > 0) {
                $amountArray->map(function ($amount) use ($type, $range, &$dateWiseAmount, $value) {
                    if ($amount[$type] == $value) {
                        $dateWiseAmount[$value] = usdToDefaultCurrency(amount: $amount['sums']);
                    }
                });
            }
        }
        return $dateWiseAmount;
    }

    public function getDateWiseAmountInUSD(array $range, string $type, object|array $amountArray): array
    {
        $dateWiseAmount = [];
        foreach ($range as $value) {
            $dateWiseAmount[$value] = 0;
        }
        foreach ($range as $value) {
            if (count($amountArray) > 0) {
                $amountArray->map(function ($amount) use ($type, $range, &$dateWiseAmount, $value) {
                    if ($amount[$type] == $value) {
                        $dateWiseAmount[$value] = $amount['sums'];
                    }
                });
            }
        }
        return $dateWiseAmount;
    }
}
