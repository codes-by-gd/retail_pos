<?php

namespace App\Modules\Inventory\Services;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(public readonly string $itemName, public readonly string $available, public readonly string $requested)
    {
        parent::__construct("Not enough stock for {$itemName}: available {$available}, needed {$requested}.");
    }
}
