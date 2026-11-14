<?php

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentSequence extends Model
{
    protected $fillable = ['branch_id', 'type', 'financial_year', 'last_number'];
}
