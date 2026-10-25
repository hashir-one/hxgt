<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParserRule extends Model
{
    protected $fillable = ['version', 'source', 'rules', 'status', 'effective_at', 'created_by'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'rules' => 'array', 'effective_at' => 'datetime'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
