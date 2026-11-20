<?php

namespace Momo630\LaravelDataPatches\Models;

use Illuminate\Database\Eloquent\Model;

class DataPatchApplication extends Model
{
    protected $primaryKey = 'patch_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'patch_id',
        'description',
        'outcome',
        'result',
        'input_files',
        'applied_at',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'input_files' => 'array',
            'applied_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }
}
