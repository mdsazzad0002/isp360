<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketReply extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'is_internal' => 'boolean',
    ];

    protected $appends = ['attachment_url'];

    // Old attachments sit under public/uploads; new ones are private and go through TicketFileController.
    public function getAttachmentUrlAttribute(): ?string
    {
        if (! $this->attachment) {
            return null;
        }
        return str_starts_with($this->attachment, 'uploads/') ? '/' . $this->attachment : '/ticket-file/' . $this->id;
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }
}
