<?php

namespace App\Console\Commands;

use App\Services\Messaging\MessageSender;
use Illuminate\Console\Command;

class SendMessages extends Command
{
    protected $signature = 'ilmora:send-messages';

    protected $description = 'Send queued WhatsApp/SMS messages to parents';

    public function handle(MessageSender $sender): int
    {
        $r = $sender->sendQueued();
        $this->info("Sent {$r['sent']}, failed {$r['failed']}.");

        return self::SUCCESS;
    }
}
