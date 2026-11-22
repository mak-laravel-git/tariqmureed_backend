<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per website setting (key/value). Read and written through App\Support\SiteSettings.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];
}
