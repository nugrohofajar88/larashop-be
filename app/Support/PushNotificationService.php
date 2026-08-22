<?php

namespace App\Support;

use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Kirim push notification browser ke semua device admin yang sudah subscribe
 * (PWA panel admin). Dipanggil saat ada order baru yang perlu diperhatikan admin
 * (lihat OrderPaymentService::markPaid()).
 */
class PushNotificationService
{
    public function enabled(): bool
    {
        return filled(config('services.web_push.public_key')) && filled(config('services.web_push.private_key'));
    }

    /**
     * @param  array<string, mixed>  $data  Payload tambahan (mis. url tujuan saat notif diklik)
     */
    public function notifyAdmins(string $title, string $body, array $data = []): void
    {
        if (! $this->enabled()) {
            return;
        }

        $subscriptions = PushSubscription::query()->get();
        if ($subscriptions->isEmpty()) {
            return;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => config('services.web_push.subject'),
                'publicKey' => config('services.web_push.public_key'),
                'privateKey' => config('services.web_push.private_key'),
            ],
        ]);

        $payload = json_encode(['title' => $title, 'body' => $body, 'data' => $data]);

        foreach ($subscriptions as $sub) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'keys' => [
                        'p256dh' => $sub->public_key,
                        'auth' => $sub->auth_token,
                    ],
                    'contentEncoding' => $sub->content_encoding,
                ]),
                $payload
            );
        }

        // Subscription yang device/browser-nya sudah uninstall/hapus izin notif akan
        // dilaporkan "expired" oleh push service - hapus dari tabel supaya gak terus
        // dicoba-kirim tiap ada order baru.
        foreach ($webPush->flush() as $report) {
            if (! $report->isSuccess() && $report->isSubscriptionExpired()) {
                PushSubscription::query()->where('endpoint', $report->getEndpoint())->delete();
            }
        }
    }
}
