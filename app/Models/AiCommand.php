<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AiCommand extends Model
{
    protected $fillable = [
        'collaborator_user_id',
        'intent',
        'parameters',
        'status',
        'campaign_id',
        'estimated_recipients',
        'executed_at',
    ];

    protected $casts = [
        'parameters' => 'array',
        'executed_at' => 'datetime',
    ];

    public function confirm(): void
    {
        if ($this->status !== 'PROPOSED') {
            throw new LogicException('Seule une commande PROPOSED peut être confirmée.');
        }

        $this->update(['status' => 'CONFIRMED']);
    }

    public function cancel(): void
    {
        if ($this->status !== 'PROPOSED') {
            throw new LogicException('Seule une commande PROPOSED peut être annulée.');
        }

        $this->update(['status' => 'CANCELLED']);
    }
}
