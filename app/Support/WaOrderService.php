<?php

namespace App\Support;

use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShipmentOrigin;
use App\Models\User;
use App\Models\UserUniqueCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Alur pemesanan via WhatsApp (state machine percakapan).
 *
 * Langkah:
 *   /pesan -> pilih item -> cari wilayah -> pilih wilayah -> pilih ongkir
 *          -> nama & alamat -> order dibuat (pending_payment).
 *
 * State percakapan disimpan di Cache per nomor (TTL 1 jam).
 */
class WaOrderService
{
    private const TTL_MINUTES = 60;

    public function __construct(
        private readonly WablasService $wablas,
    ) {
    }

    public function hasSession(string $phone): bool
    {
        return Cache::has($this->key($phone));
    }

    public function isTrigger(string $text): bool
    {
        return in_array(strtolower(trim($text)), ['/pesan', 'pesan', 'order', '/order'], true);
    }

    /**
     * Pengingat lanjutan kalau ada sesi order yang belum selesai (sesuai langkahnya).
     * null kalau tidak ada sesi.
     */
    public function continueHint(string $phone): ?string
    {
        $session = Cache::get($this->key($phone));

        if ($session === null) {
            return null;
        }

        $action = match ($session['step'] ?? '') {
            'await_product', 'await_more' => 'ketik *nomor produk*',
            'await_variant' => 'ketik *nomor varian* (atau sekalian jumlah, mis. *1-2*)',
            'await_qty' => 'ketik *jumlah*-nya',
            'await_destination_query' => 'ketik *nama kecamatan/kota tujuan*',
            'await_destination_pick' => 'balas *angka wilayah* pilihan',
            'await_shipping_pick' => 'balas *angka layanan kirim* pilihan',
            'await_recipient' => 'ketik *Nama | Alamat lengkap*',
            default => 'balas pesan terakhir untuk melanjutkan',
        };

        return "📌 Oh ya, pesananmu tadi belum selesai. Kalau mau lanjut, {$action}. Ketik *batal* untuk membatalkan.";
    }

    /**
     * Proses satu pesan dalam alur order. Mengembalikan teks balasan.
     */
    public function handle(string $phone, string $message): string
    {
        $text = trim($message);
        $lower = strtolower($text);

        if (in_array($lower, ['batal', '/batal', 'cancel'], true)) {
            $this->forget($phone);

            return "Pesanan dibatalkan. Ketik */pesan* untuk mulai lagi.";
        }

        if ($this->isTrigger($lower)) {
            return $this->start($phone);
        }

        $session = Cache::get($this->key($phone));

        if ($session === null) {
            return $this->start($phone);
        }

        return match ($session['step'] ?? '') {
            'await_product' => $this->handleProduct($phone, $session, $text),
            'await_variant' => $this->handleVariant($phone, $session, $text),
            'await_qty' => $this->handleQty($phone, $session, $text),
            'await_more' => $this->handleMore($phone, $session, $text),
            'await_destination_query' => $this->handleDestinationQuery($phone, $session, $text),
            'await_destination_pick' => $this->handleDestinationPick($phone, $session, $text),
            'await_shipping_pick' => $this->handleShippingPick($phone, $session, $text),
            'await_recipient' => $this->handleRecipient($phone, $session, $text),
            default => $this->start($phone),
        };
    }

    /* ----------------------------------------------------------------- */
    /* Steps                                                              */
    /* ----------------------------------------------------------------- */

    protected function start(string $phone): string
    {
        $products = Product::query()->with('variants')->orderBy('id')->limit(30)->get();

        if ($products->isEmpty()) {
            return "Maaf, belum ada produk yang bisa dipesan.";
        }

        $lines = $products->map(function (Product $p): string {
            $price = $this->money((int) $p->price);

            return "{$p->id}. {$p->name} — {$price} (stok {$p->stock})";
        })->implode("\n");

        $this->put($phone, ['step' => 'await_product', 'items' => []]);

        return "🛒 *Pesan via WhatsApp*\n\n{$lines}\n\n"
            ."Ketik *nomor produk* yang ingin dipesan (contoh: *1*).\n\n"
            ."Ketik *batal* untuk berhenti.";
    }

    protected function handleProduct(string $phone, array $session, string $text): string
    {
        $id = (int) preg_replace('/\D/', '', $text);
        $product = $id > 0 ? Product::query()->with('variants')->find($id) : null;

        if ($product === null) {
            return "Produk tidak ditemukan. Ketik *nomor produk* dari daftar (contoh: *1*).";
        }

        return $this->askVariantOrQty($phone, $session, $product);
    }

    protected function askVariantOrQty(string $phone, array $session, Product $product): string
    {
        $variants = $product->variants->values();

        // Cuma 1 varian (atau tanpa varian) -> langsung tanya jumlah.
        if ($variants->count() <= 1) {
            $session['pending'] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'variant' => $this->variantData($variants->first(), $product),
            ];
            $session['step'] = 'await_qty';
            $this->put($phone, $session);

            $label = $variants->first()->label ?? '-';

            return "Berapa jumlah *{$product->name}* ({$label})? Ketik angka, contoh: *2*";
        }

        // Banyak varian -> minta pilih dulu.
        $session['pending'] = ['product_id' => $product->id, 'product_name' => $product->name];
        $session['variant_list'] = $variants->map(fn ($v): array => $this->variantData($v, $product))->all();
        $session['step'] = 'await_variant';
        $this->put($phone, $session);

        $list = $variants->values()->map(
            fn ($v, int $i): string => ($i + 1).". {$v->label} — ".$this->money((int) $v->price)." (stok {$v->stock})"
        )->implode("\n");

        return "Pilih varian *{$product->name}*:\n\n{$list}\n\n"
            ."Balas *nomorVarian-jumlah* (contoh: *1-2* = varian 1 sebanyak 2), atau ketik nomor variannya saja.";
    }

    protected function handleVariant(string $phone, array $session, string $text): string
    {
        $variants = $session['variant_list'] ?? [];

        // Ambil angka di pesan: angka-1 = nomor varian, angka-2 (opsional) = jumlah.
        preg_match_all('/\d+/', $text, $matches);
        $nums = $matches[0] ?? [];

        $pick = isset($nums[0]) ? (int) $nums[0] : 0;

        if ($pick < 1 || $pick > count($variants)) {
            return "Balas dengan angka varian yang ada di daftar (boleh sekalian jumlahnya, contoh: *1-2*).";
        }

        $variant = $variants[$pick - 1];

        // Format gabungan: "varian-jumlah" -> langsung tambahkan.
        if (isset($nums[1]) && (int) $nums[1] >= 1) {
            $session['pending'] = array_merge($session['pending'] ?? [], ['variant' => $variant]);

            return $this->commitItem($phone, $session, $variant, (int) $nums[1]);
        }

        // Hanya nomor varian -> tanya jumlah.
        $session['pending'] = array_merge($session['pending'] ?? [], ['variant' => $variant]);
        unset($session['variant_list']);
        $session['step'] = 'await_qty';
        $this->put($phone, $session);

        return "Berapa jumlah *{$session['pending']['product_name']}* ({$variant['label']})? Ketik angka, contoh: *2*";
    }

    protected function handleQty(string $phone, array $session, string $text): string
    {
        $qty = (int) preg_replace('/\D/', '', $text);
        $variant = $session['pending']['variant'] ?? null;

        if ($variant === null) {
            return $this->start($phone);
        }

        return $this->commitItem($phone, $session, $variant, $qty);
    }

    /**
     * Tambahkan item ke keranjang sesi (dipakai jalur "varian-jumlah" maupun tanya-jumlah).
     */
    protected function commitItem(string $phone, array $session, array $variant, int $qty): string
    {
        if ($qty < 1) {
            return "Ketik jumlah berupa angka, contoh: *2*";
        }

        if ($qty > (int) $variant['stock']) {
            return "Stok *{$variant['label']}* cuma {$variant['stock']}. Ketik jumlah lebih kecil.";
        }

        $pending = $session['pending'] ?? [];

        $session['items'][] = [
            'product_id' => $pending['product_id'],
            'product_variant_id' => $variant['id'],
            'name' => $pending['product_name'],
            'sku' => $variant['sku'],
            'variant_label' => $variant['label'],
            'price' => (int) $variant['price'],
            'qty' => $qty,
            'weight' => (int) $variant['weight'],
            'length_cm' => $variant['length_cm'],
            'width_cm' => $variant['width_cm'],
            'height_cm' => $variant['height_cm'],
        ];
        unset($session['pending'], $session['variant_list']);
        $session['step'] = 'await_more';
        $this->put($phone, $session);

        return $this->itemsSummary($session['items'])
            ."\n\nKetik *nomor produk* lain untuk menambah, atau *selesai* untuk lanjut ke pengiriman.";
    }

    protected function handleMore(string $phone, array $session, string $text): string
    {
        $lower = strtolower(trim($text));

        if (in_array($lower, ['selesai', 'lanjut', 'checkout', 'done', 'lanjutkan'], true)) {
            if (($session['items'] ?? []) === []) {
                return "Belum ada item. Ketik *nomor produk* dulu.";
            }

            $session['step'] = 'await_destination_query';
            $this->put($phone, $session);

            return "Sip! Sekarang ketik *nama kecamatan/kota tujuan* untuk cek ongkir.\nContoh: *Lowokwaru* atau *Bogor*";
        }

        // Selain 'selesai', anggap user mengetik nomor produk lain.
        return $this->handleProduct($phone, $session, $text);
    }

    protected function variantData($variant, ?Product $product = null): array
    {
        return [
            'id' => $variant?->id,
            'label' => $variant?->label ?? '-',
            'sku' => $variant?->sku ?? $product?->sku,
            'price' => (int) ($variant?->price ?? $product?->price ?? 0),
            'stock' => (int) ($variant?->stock ?? $product?->stock ?? 0),
            'weight' => (int) ($variant?->weight_grams ?? $product?->weight_grams ?? 0),
            'length_cm' => (float) ($variant?->length_cm ?? $product?->length_cm ?? 0),
            'width_cm' => (float) ($variant?->width_cm ?? $product?->width_cm ?? 0),
            'height_cm' => (float) ($variant?->height_cm ?? $product?->height_cm ?? 0),
        ];
    }

    protected function handleDestinationQuery(string $phone, array $session, string $text): string
    {
        if (mb_strlen($text) < 3) {
            return "Ketik minimal 3 huruf nama wilayah tujuan. Contoh: *Bogor*";
        }

        $results = $this->searchDestinations($text);

        if ($results === []) {
            return "Wilayah \"{$text}\" tidak ditemukan. Coba kata lain (nama kecamatan/kota).";
        }

        $session['destinations'] = $results;
        $session['step'] = 'await_destination_pick';
        $this->put($phone, $session);

        $list = collect($results)->values()->map(
            fn (array $d, int $i): string => ($i + 1).". {$d['label']}"
        )->implode("\n");

        return "📍 Pilih wilayah tujuan (balas angkanya):\n\n{$list}\n\nKalau tidak ada, ketik nama lain.";
    }

    protected function handleDestinationPick(string $phone, array $session, string $text): string
    {
        $pick = (int) preg_replace('/\D/', '', $text);
        $results = $session['destinations'] ?? [];

        if ($pick < 1 || $pick > count($results)) {
            // Mungkin user mengetik nama wilayah baru, bukan angka.
            return $this->handleDestinationQuery($phone, $session, $text);
        }

        $destination = $results[$pick - 1];

        $shipping = $this->shippingOptions($destination['id'], $this->totalWeight($session['items']));

        if ($shipping === []) {
            return "Maaf, ongkir ke wilayah itu belum tersedia. Coba pilih wilayah lain (ketik nama wilayah).";
        }

        $session['destination'] = $destination;
        $session['shipping_options'] = $shipping;
        $session['step'] = 'await_shipping_pick';
        $this->put($phone, $session);

        $list = collect($shipping)->values()->map(
            fn (array $s, int $i): string => ($i + 1).". {$s['service']} — {$s['price']} (estimasi {$s['estimate']})"
        )->implode("\n");

        return "🚚 Pilih layanan kirim (balas angkanya):\n\n{$list}";
    }

    protected function handleShippingPick(string $phone, array $session, string $text): string
    {
        $pick = (int) preg_replace('/\D/', '', $text);
        $options = $session['shipping_options'] ?? [];

        if ($pick < 1 || $pick > count($options)) {
            return "Balas dengan angka layanan kirim yang ada di daftar ya.";
        }

        $session['shipping'] = $options[$pick - 1];
        $session['step'] = 'await_recipient';
        $this->put($phone, $session);

        return "Hampir selesai! Ketik *nama penerima* dan *alamat lengkap*, pisahkan dengan tanda |\n\n"
            ."Contoh:\n*Budi Santoso | Jl. Mawar No. 10 RT 1 RW 2, dekat masjid*";
    }

    protected function handleRecipient(string $phone, array $session, string $text): string
    {
        $parts = array_map('trim', explode('|', $text, 2));
        $name = $parts[0] ?? '';
        $addressLine = $parts[1] ?? '';

        if ($name === '' || $addressLine === '') {
            return "Formatnya: *Nama | Alamat lengkap*\nContoh: *Budi | Jl. Mawar 10, Bogor*";
        }

        $order = $this->createOrder($phone, $session, $name, $addressLine);

        $this->forget($phone);

        return $this->orderConfirmation($order);
    }

    /* ----------------------------------------------------------------- */
    /* Order creation                                                     */
    /* ----------------------------------------------------------------- */

    protected function createOrder(string $phone, array $session, string $name, string $addressLine): Order
    {
        $user = $this->findOrCreateCustomer($phone, $name);
        $dest = $session['destination'];
        $shipping = $session['shipping'];
        $items = $session['items'];

        $itemsTotal = (int) collect($items)->sum(fn (array $i): int => $i['price'] * $i['qty']);
        $shippingTotal = (int) ($shipping['price_value'] ?? 0);
        $uniqueCode = $this->usesUniqueCode() ? random_int(101, 999) : 0;
        $grandTotal = max(0, $itemsTotal + $shippingTotal + $uniqueCode);

        return DB::transaction(function () use (
            $user, $dest, $shipping, $items, $addressLine, $name, $phone,
            $itemsTotal, $shippingTotal, $uniqueCode, $grandTotal
        ): Order {
            $address = CustomerAddress::query()->create([
                'user_id' => $user->id,
                'label' => 'Alamat WA',
                'recipient_name' => $name,
                'recipient_phone' => $phone,
                'destination_id' => $dest['id'],
                'province' => $dest['province_name'] ?? '',
                'city' => $dest['city_name'] ?? '',
                'district' => $dest['district_name'] ?? '',
                'subdistrict' => $dest['subdistrict_name'] ?? '',
                'postal_code' => $dest['zip_code'] ?? '',
                'address_line' => $addressLine,
                'is_primary' => $user->addresses()->count() === 0,
            ]);

            $order = Order::query()->create([
                'code' => $this->generateOrderCode(),
                'user_id' => $user->id,
                'customer_address_id' => $address->id,
                'status' => 'pending_payment',
                'payment_method' => 'Transfer manual',
                'payment_status' => 'Menunggu transfer',
                'items_total' => $itemsTotal,
                'shipping_total' => $shippingTotal,
                'unique_code' => $uniqueCode,
                'used_unique_code' => 0,
                'grand_total' => $grandTotal,
                'shipping_service_name' => $shipping['service'] ?? null,
                'shipping_estimate_days' => $shipping['estimate'] ?? null,
                'shipment_note' => 'Order via WhatsApp. Menunggu validasi pembayaran.',
                'recipient_name' => $name,
                'recipient_phone' => $phone,
                'address_label' => $address->label,
                'address_snapshot' => ApiData::addressSummary($address),
            ]);

            foreach ($items as $item) {
                $order->items()->create([
                    'product_id' => $item['product_id'],
                    'product_variant_id' => $item['product_variant_id'],
                    'product_name' => $item['name'],
                    'product_sku' => $item['sku'],
                    'variant_label' => $item['variant_label'],
                    'weight_grams' => $item['weight'],
                    'price' => $item['price'],
                    'quantity' => $item['qty'],
                    'subtotal' => $item['price'] * $item['qty'],
                ]);
            }

            if ($uniqueCode > 0) {
                UserUniqueCode::query()->create([
                    'user_id' => $user->id,
                    'value' => $uniqueCode,
                    'ref_id' => $order->id,
                    'type' => 'paid',
                ]);
            }

            return $order->fresh('items');
        });
    }

    protected function orderConfirmation(Order $order): string
    {
        $lines = $order->items->map(
            fn ($i): string => "• {$i->quantity}x {$i->product_name}".
                ($i->variant_label ? " ({$i->variant_label})" : '').
                " = ".$this->money((int) $i->subtotal)
        )->implode("\n");

        return "✅ *Pesanan berhasil dibuat!*\n\n"
            ."Kode: *{$order->code}*\n\n"
            ."{$lines}\n"
            ."Subtotal: ".$this->money((int) $order->items_total)."\n"
            ."Ongkir: ".$this->money((int) $order->shipping_total)."\n"
            .($order->unique_code > 0 ? "Kode unik: ".$this->money((int) $order->unique_code)."\n" : '')
            ."*Total transfer: ".$this->money((int) $order->grand_total)."*\n\n"
            ."Silakan transfer sesuai *total di atas (termasuk kode unik)* lalu kirim bukti ke chat ini. "
            ."Admin akan memvalidasi pembayaranmu. 🙏";
    }

    /* ----------------------------------------------------------------- */
    /* RajaOngkir                                                          */
    /* ----------------------------------------------------------------- */

    protected function searchDestinations(string $search): array
    {
        return app(ShippingService::class)->searchDestinations($search, 5);
    }

    protected function shippingOptions(int|string $destinationId, int $weight): array
    {
        return app(ShippingService::class)->costOptions($destinationId, $weight);
    }

    /* ----------------------------------------------------------------- */
    /* Helpers                                                            */
    /* ----------------------------------------------------------------- */

    protected function itemsSummary(array $items): string
    {
        $lines = collect($items)->map(
            fn (array $i): string => "• {$i['qty']}x {$i['name']}".
                (($i['variant_label'] ?? '') !== '' ? " ({$i['variant_label']})" : '').
                " = ".$this->money($i['price'] * $i['qty'])
        )->implode("\n");

        $total = collect($items)->sum(fn (array $i): int => $i['price'] * $i['qty']);

        return "Ringkasan sementara:\n{$lines}\nSubtotal: ".$this->money((int) $total);
    }

    protected function totalWeight(array $items): int
    {
        return ShippingWeight::chargeableGrams(array_map(fn (array $i): array => [
            'weight_grams' => (int) $i['weight'],
            'length_cm' => $i['length_cm'] ?? 0,
            'width_cm' => $i['width_cm'] ?? 0,
            'height_cm' => $i['height_cm'] ?? 0,
            'qty' => (int) $i['qty'],
        ], $items));
    }

    protected function findOrCreateCustomer(string $phone, string $name): User
    {
        $user = User::query()->where('phone', $phone)->first();

        if ($user !== null) {
            return $user;
        }

        return User::query()->create([
            'code' => 'CUST-WA-'.Str::upper(Str::random(6)),
            'name' => $name !== '' ? $name : 'Pelanggan WA '.substr($phone, -4),
            'username' => $phone,
            'phone' => $phone,
            'role' => 'customer',
            'status' => 'active',
            // Default password = username (nomor WA). Cast 'hashed' di model User
            // otomatis meng-hash nilai ini saat disimpan.
            'password' => $phone,
        ]);
    }

    protected function generateOrderCode(): string
    {
        $next = ((int) Order::query()->where('status', '!=', 'draft')->count()) + 1;

        do {
            $code = 'ORD-'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
            $next++;
        } while (Order::query()->where('code', $code)->exists());

        return $code;
    }

    protected function usesUniqueCode(): bool
    {
        return (bool) config('services.checkout.use_unique_code', true);
    }

    protected function money(int $value): string
    {
        return 'Rp'.number_format($value, 0, ',', '.');
    }

    protected function rajaBase(): string
    {
        return rtrim((string) config('services.rajaongkir.base_url'), '/').'/';
    }

    protected function key(string $phone): string
    {
        return 'wa_order:'.$phone;
    }

    protected function put(string $phone, array $session): void
    {
        Cache::put($this->key($phone), $session, now()->addMinutes(self::TTL_MINUTES));
    }

    protected function forget(string $phone): void
    {
        Cache::forget($this->key($phone));
    }
}
