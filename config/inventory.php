<?php

return [
    /*
    | Allow stock at a location to go below zero when moving / selling.
    |
    | Go-live tip: while the warehouse has not been physically counted yet,
    | set INVENTORY_ALLOW_NEGATIVE=true so staff can keep moving stock; the
    | "Stock by Location" report then shows negative balances, which are
    | corrected with a Stock Adjustment (Physical Count) once counted.
    | Switch it back to false after the count.
    */
    'allow_negative' => env('INVENTORY_ALLOW_NEGATIVE', false),
];
