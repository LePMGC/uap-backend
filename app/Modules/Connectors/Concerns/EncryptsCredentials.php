<?php
namespace App\Modules\Connectors\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Crypt;

trait EncryptsCredentials {
    /**
     * Modern Laravel 11 Attribute Cast
     */
    protected function credentials(): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => Crypt::decryptString($value),
            set: fn (string $value) => Crypt::encryptString($value),
        );
    }
}
