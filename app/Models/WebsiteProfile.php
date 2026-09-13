<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'collaborator_user_id',
        'url',
        'business_name',
        'activity_sector',
        'key_offerings',
        'brand_tone',
        'raw_metadata',
    ];

    protected $casts = [
        'key_offerings' => 'array',
        'raw_metadata'  => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

