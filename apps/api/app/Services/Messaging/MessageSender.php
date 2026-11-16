<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends queued parent messages (WhatsApp / SMS).
 *
 * Driver "log" only writes to the log, for development and demos.
 * Driver "http" posts {to, channel, body} to MESSAGE_HTTP_URL with a bearer token,
 * which fits most WhatsApp Business API providers and Pakistani SMS gateways behind a small adapter.
 */
final class MessageSender
{
    public function sendQueued(int $limit = 200): array
    {
        $sent = $failed = 0;
        foreach (DB::table('messages')->where('status', 'queued')->orderBy('created_at')->limit($limit)->get() as $m) {
            $error = $this->deliver($m);
            DB::table('messages')->where('id', $m->id)->update([
                'status' => $error ? 'failed' : 'sent', 'sent_at' => $error ? null : now(), 'error' => $error,
                'version' => DB::raw('version + 1'), 'updated_at' => now(),
            ]);
            $error ? $failed++ : $sent++;
        }

        return compact('sent', 'failed');
    }

    private function deliver(object $m): ?string
    {
        if (config('ilmora.messaging.driver') !== 'http') {
            Log::info("[message:{$m->channel}] {$m->to_phone}: {$m->body}");

            return null;
        }
        try {
            $res = Http::withToken((string) config('ilmora.messaging.http_token'))->timeout(15)
                ->post((string) config('ilmora.messaging.http_url'), ['to' => $m->to_phone, 'channel' => $m->channel, 'body' => $m->body]);

            return $res->successful() ? null : mb_substr("HTTP {$res->status()}", 0, 250);
        } catch (\Throwable $e) {
            return mb_substr($e->getMessage(), 0, 250);
        }
    }
}
