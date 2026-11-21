<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyEnvironmentChange extends Model
{
    public const UPDATED_AT = null;

    /** Campos que deciden a dónde va cada comprobante. */
    public const WATCHED = ['modo_produccion', 'cpe_provider', 'gre_modo_produccion'];

    protected $fillable = ['company_id', 'field', 'old_value', 'new_value', 'source', 'user_id', 'ip'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string) $value;
    }
}
