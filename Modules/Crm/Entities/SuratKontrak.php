<?php

namespace Modules\Crm\Entities;

use Illuminate\Database\Eloquent\Model;

class SuratKontrak extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'surat_kontrak';

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];
}
