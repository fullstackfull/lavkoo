<?php

namespace App\Services\Loyalty;

use RuntimeException;

/** Thrown inside a points conversion so that its wallet credit is rolled back when the points cannot be debited. */
final class PointsConversionRefused extends RuntimeException
{
}
