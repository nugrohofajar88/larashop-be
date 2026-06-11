<?php

namespace App\Support\Contracts;

/**
 * Kontrak gateway WhatsApp — implementasi: WablasService / FonnteService.
 * Driver aktif dipilih via config services.whatsapp.driver (WHATSAPP_DRIVER).
 */
interface WhatsappGateway
{
    public function sendMessage(string $phone, string $message): bool;

    public function sendImage(string $phone, string $imageUrl, string $caption = ''): bool;
}
