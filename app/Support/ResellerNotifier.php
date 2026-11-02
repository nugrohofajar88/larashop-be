<?php

namespace App\Support;

use App\Models\Reseller;
use App\Models\ResellerCommission;
use App\Models\ResellerWithdrawal;

/**
 * Notifikasi WA ke reseller untuk event penting: disetujui, komisi masuk,
 * dan status pencairan - satu tempat supaya formatnya konsisten. Dipanggil
 * dari AdminResellerController (approve/approveWithdrawal/rejectWithdrawal)
 * dan ResellerCommissionService (confirmForOrder).
 */
class ResellerNotifier
{
    public function sendApproved(Reseller $reseller): void
    {
        $reseller->loadMissing('user');
        $phone = $this->normalizePhone((string) ($reseller->user?->phone ?? ''));

        if ($phone === '') {
            return;
        }

        $komisi = $reseller->commission_type === 'fixed'
            ? 'Rp'.number_format((int) $reseller->commission_value, 0, ',', '.').' / order'
            : $reseller->commission_value.'%';

        $message = "🎉 Selamat! Pendaftaran reseller kamu di *Akar Tani Kimia* sudah *disetujui*.\n\n"
            ."Kode referral kamu: *{$reseller->referral_code}*\n"
            ."Komisi: {$komisi} dari tiap pembeli yang belanja lewat kode referral kamu.\n\n"
            .'Bagikan kodenya sekarang, dan pantau komisi & saldo kamu lewat halaman Reseller di akunmu. Terima kasih sudah bergabung! 🙏';

        app(\App\Support\Contracts\WhatsappGateway::class)->sendMessage($phone, $message);
    }

    public function sendCommissionConfirmed(ResellerCommission $commission): void
    {
        $commission->loadMissing('reseller.user');
        $reseller = $commission->reseller;
        $phone = $this->normalizePhone((string) ($reseller?->user?->phone ?? ''));

        if ($phone === '' || $reseller === null) {
            return;
        }

        $amount = 'Rp'.number_format((int) $commission->amount, 0, ',', '.');
        $balance = 'Rp'.number_format($reseller->balance(), 0, ',', '.');

        $message = "💰 Komisi baru masuk!\n\n"
            ."Order *{$commission->order_code}* ({$amount}) sudah dikonfirmasi.\n"
            ."Saldo komisi kamu sekarang: {$balance}\n\n"
            .'Cek detailnya di halaman Reseller.';

        app(\App\Support\Contracts\WhatsappGateway::class)->sendMessage($phone, $message);
    }

    public function sendWithdrawalProcessed(ResellerWithdrawal $withdrawal, bool $approved): void
    {
        $withdrawal->loadMissing('reseller.user');
        $phone = $this->normalizePhone((string) ($withdrawal->reseller?->user?->phone ?? ''));

        if ($phone === '') {
            return;
        }

        $amount = 'Rp'.number_format((int) $withdrawal->amount, 0, ',', '.');

        $message = $approved
            ? "✅ Pengajuan pencairan komisi kamu sebesar *{$amount}* sudah *disetujui* dan akan segera ditransfer. Terima kasih!"
            : "❌ Pengajuan pencairan komisi kamu sebesar *{$amount}* *ditolak*."
                .($withdrawal->admin_note ? "\nAlasan: {$withdrawal->admin_note}" : '');

        app(\App\Support\Contracts\WhatsappGateway::class)->sendMessage($phone, $message);
    }

    protected function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/[^0-9]/', '', $raw) ?? '';

        if ($digits === '') {
            return '';
        }

        return str_starts_with($digits, '0') ? '62'.substr($digits, 1) : $digits;
    }
}
