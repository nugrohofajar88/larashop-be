<?php

return [
    // Kill switch deploy: matikan SELURUH fitur reseller/afiliasi (route publik
    // maupun admin) tanpa perlu rollback kode - dipakai kalau kodenya mau
    // di-deploy duluan ke production tapi belum siap dibuka ke pengguna.
    // Beda dari Setting::resellerEnabled() (DB, bisa di-toggle admin dari
    // panel tanpa deploy) - ini level infra, butuh ubah .env + config:clear.
    'reseller' => env('RESELLER_FEATURE_ENABLED', false),
];
