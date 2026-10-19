<?php
/**
 * DEV ONLY — plays a pretend timed game rally by rally, the way league
 * games run: best of three sets to 25 (win by 2), the third set to 15 or
 * 25, all inside an hour. When time's up mid-set the score as it stands
 * counts as a set win (a tied set is dropped). Rallies aren't perfectly
 * independent — the team that won the last rally gets a small lift — so a
 * rating model can't be fooled into trusting every point.
 *
 * Used by dev/fake-season.php and dev/simulate-pairing.php.
 */

if (!function_exists('rsl_dev_play_game')) {
    /**
     * $edge: home strength minus away strength, in rating points.
     * $opts: rallies (per hour, default 115-135), third_to (15|25, default either).
     * Returns list of array(home_points, away_points).
     */
    function rsl_dev_play_game($edge, $opts = array()) {
        $rallies = isset($opts['rallies']) ? $opts['rallies'] : mt_rand(115, 135);
        $third_to = isset($opts['third_to']) ? $opts['third_to'] : (mt_rand(0, 1) ? 15 : 25);
        // True rally chance: a set edge is roughly six times a rally edge in a race to 25.
        $q = 1 / (1 + exp(-($edge * log(10) / 400) / 6));
        $sets = array();
        $won = array(0, 0);
        $h = 0;
        $a = 0;
        $last = -1;
        for ($r = 0; $r < $rallies; $r++) {
            $lift = $last === 0 ? 0.03 : ($last === 1 ? -0.03 : 0);
            if ((mt_rand() / mt_getrandmax()) < $q + $lift) {
                $h++;
                $last = 0;
            } else {
                $a++;
                $last = 1;
            }
            $target = count($sets) === 2 ? $third_to : 25;
            if (($h >= $target || $a >= $target) && abs($h - $a) >= 2) {
                $sets[] = array($h, $a);
                $won[$h > $a ? 0 : 1]++;
                $h = $a = 0;
                $last = -1;
                if ($won[0] === 2 || $won[1] === 2) {
                    return $sets;
                }
            }
        }
        if ($h !== $a) {
            $sets[] = array($h, $a); // time's up: the set as it stands
        }
        return $sets ?: array(array(1, 0));
    }
}
