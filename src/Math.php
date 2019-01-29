<?php

declare(strict_types=1);

namespace Geokit;

final class Math
{
    /**
     * Calculates the approximate sea level great circle (Earth) distance
     * between two points using the Haversine formula.
     *
     * @see http://en.wikipedia.org/wiki/Haversine_formula
     * @see http://www.movable-type.co.uk/scripts/latlong.html
     */
    public function distanceHaversine(LatLng $from, LatLng $to): Distance
    {
        $lat1 = \deg2rad($from->getLatitude());
        $lng1 = \deg2rad($from->getLongitude());
        $lat2 = \deg2rad($to->getLatitude());
        $lng2 = \deg2rad($to->getLongitude());

        $dLat = $lat2 - $lat1;
        $dLon = $lng2 - $lng1;

        $a = \sin($dLat / 2) * \sin($dLat / 2) +
            \cos($lat1) * \cos($lat2) *
            \sin($dLon / 2) * \sin($dLon / 2);

        $c = 2 * \atan2(\sqrt($a), \sqrt(1 - $a));

        return new Distance(WGS84::SEMI_MAJOR_AXIS * $c);
    }

    /**
     * Calculates the geodetic distance between two points using the
     * Vincenty inverse formula for ellipsoids.
     *
     * @see http://en.wikipedia.org/wiki/Vincenty%27s_formulae
     * @see http://www.movable-type.co.uk/scripts/latlong-vincenty.html
     */
    public function distanceVincenty(LatLng $from, LatLng $to): Distance
    {
        $lat1 = $from->getLatitude();
        $lng1 = $from->getLongitude();
        $lat2 = $to->getLatitude();
        $lng2 = $to->getLongitude();

        $a = WGS84::SEMI_MAJOR_AXIS;
        $b = WGS84::SEMI_MINOR_AXIS;
        $f = WGS84::FLATTENING;

        $L = \deg2rad($lng2 - $lng1);
        $U1 = \atan((1 - $f) * \tan(\deg2rad($lat1)));
        $U2 = \atan((1 - $f) * \tan(\deg2rad($lat2)));

        $sinU1 = \sin($U1);
        $cosU1 = \cos($U1);
        $sinU2 = \sin($U2);
        $cosU2 = \cos($U2);

        $lambda = $L;
        $iterLimit = 100;

        do {
            $sinLambda = \sin($lambda);
            $cosLambda = \cos($lambda);
            $sinSigma = \sqrt(($cosU2 * $sinLambda) * ($cosU2 * $sinLambda) +
                ($cosU1 * $sinU2 - $sinU1 * $cosU2 * $cosLambda) *
                ($cosU1 * $sinU2 - $sinU1 * $cosU2 * $cosLambda));

            if (0.0 === $sinSigma) {
                return new Distance(0); // co-incident points
            }

            $cosSigma = $sinU1 * $sinU2 + $cosU1 * $cosU2 * $cosLambda;
            $sigma = \atan2($sinSigma, $cosSigma);
            $sinAlpha = $cosU1 * $cosU2 * $sinLambda / $sinSigma;
            $cosSqAlpha = (float) 1 - $sinAlpha * $sinAlpha;

            if (0.0 !== $cosSqAlpha) {
                $cos2SigmaM = $cosSigma - 2 * $sinU1 * $sinU2 / $cosSqAlpha;
            } else {
                $cos2SigmaM = 0.0; // Equatorial line
            }

            $C = $f / 16 * $cosSqAlpha * (4 + $f * (4 - 3 * $cosSqAlpha));
            $lambdaP = $lambda;
            $lambda = $L + (1 - $C) * $f * $sinAlpha *
                ($sigma + $C * $sinSigma * ($cos2SigmaM + $C * $cosSigma * (-1 + 2 * $cos2SigmaM * $cos2SigmaM)));
        } while (\abs($lambda - $lambdaP) > 1e-12 && --$iterLimit > 0);

        if ($iterLimit === 0) {
            throw new Exception\RuntimeException('Vincenty formula failed to converge.');
        }

        $uSq = $cosSqAlpha * ($a * $a - $b * $b) / ($b * $b);
        $A = 1 + $uSq / 16384 * (4096 + $uSq * (-768 + $uSq * (320 - 175 * $uSq)));
        $B = $uSq / 1024 * (256 + $uSq * (-128 + $uSq * (74 - 47 * $uSq)));
        $deltaSigma = $B * $sinSigma * ($cos2SigmaM + $B / 4 * ($cosSigma * (-1 + 2 * $cos2SigmaM * $cos2SigmaM) -
                    $B / 6 * $cos2SigmaM * (-3 + 4 * $sinSigma * $sinSigma) * (-3 + 4 * $cos2SigmaM * $cos2SigmaM)));
        $s = $b * $A * ($sigma - $deltaSigma);

        return new Distance($s);
    }

    /**
     * Calculates the (initial) heading from the first point to the second point
     * in degrees.
     */
    public function heading(LatLng $from, LatLng $to): float
    {
        $lat1 = $from->getLatitude();
        $lng1 = $from->getLongitude();
        $lat2 = $to->getLatitude();
        $lng2 = $to->getLongitude();

        $lat1 = \deg2rad($lat1);
        $lat2 = \deg2rad($lat2);
        $dLon = \deg2rad($lng2 - $lng1);

        $y = \sin($dLon) * \cos($lat2);
        $x = \cos($lat1) * \sin($lat2) -
            \sin($lat1) * \cos($lat2) * \cos($dLon);

        $heading = \atan2($y, $x);

        return \fmod(\rad2deg($heading) + 360, 360);
    }

    /**
     * Calculates an intermediate point on the geodesic between the two given
     * points.
     *
     * @see http://www.movable-type.co.uk/scripts/latlong.html
     */
    public function midpoint(LatLng $from, LatLng $to): LatLng
    {
        $lat1 = $from->getLatitude();
        $lng1 = $from->getLongitude();
        $lat2 = $to->getLatitude();
        $lng2 = $to->getLongitude();

        $lat1 = \deg2rad($lat1);
        $lat2 = \deg2rad($lat2);
        $dLon = \deg2rad($lng2 - $lng1);

        $Bx = \cos($lat2) * \cos($dLon);
        $By = \cos($lat2) * \sin($dLon);

        $lat3 = \atan2(
            \sin($lat1) + \sin($lat2),
            \sqrt((\cos($lat1) + $Bx) * (\cos($lat1) + $Bx) + $By * $By)
        );
        $lon3 = \deg2rad($lng1) + \atan2($By, \cos($lat1) + $Bx);

        return new LatLng(\rad2deg($lat3), \rad2deg($lon3));
    }

    /**
     * Calculates the destination point along a geodesic, given an initial
     * heading and distance, from the given start point.
     *
     * @see http://www.movable-type.co.uk/scripts/latlong.html
     */
    public function endpoint(LatLng $start, float $heading, Distance $distance): LatLng
    {
        $lat = \deg2rad($start->getLatitude());
        $lng = \deg2rad($start->getLongitude());

        $angularDistance = $distance->meters() / WGS84::SEMI_MAJOR_AXIS;
        $heading = \deg2rad($heading);

        $lat2 = \asin(
            \sin($lat) * \cos($angularDistance) +
            \cos($lat) * \sin($angularDistance) * \cos($heading)
        );
        $lon2 = $lng + \atan2(
            \sin($heading) * \sin($angularDistance) * \cos($lat),
            \cos($angularDistance) - \sin($lat) * \sin($lat2)
        );

        return new LatLng(\rad2deg($lat2), \rad2deg($lon2));
    }

    public function circle(
        LatLng $center,
        Distance $radius,
        int $steps
    ): Polygon {
        $points = [];

        for ($i = 0; $i <= $steps; $i++) {
            $points[] = $this->endpoint(
                $center,
                $i * (-360 / $steps),
                $radius
            );
        }

        return new Polygon($points);
    }
}
