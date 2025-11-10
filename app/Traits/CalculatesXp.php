<?php

namespace App\Traits;

trait CalculatesXp {

    public function getXpFromRarity($percent): int
    {
        if ($percent < 1.0) { return 500; }
        if ($percent < 10.0) { return 150; }
        if ($percent < 25.0) { return 50; }
        if ($percent < 50.0) { return 25; }

        return 10;
    }
}
