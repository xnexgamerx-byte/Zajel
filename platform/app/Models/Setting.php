<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $guarded = ['id'];

    public function typedValue(): mixed
    {
        return match ($this->type) {
            'int'  => (int) $this->value,
            'bool' => filter_var($this->value, FILTER_VALIDATE_BOOL),
            'json' => json_decode((string) $this->value, true),
            default => $this->value,
        };
    }
}
