<?php
// =============================================
// CEDEKA WC — Notificaciones Telegram
// =============================================

define('TELEGRAM_TOKEN',   getenv('TELEGRAM_TOKEN')   ?: '');
define('TELEGRAM_CHAT_ID', getenv('TELEGRAM_CHAT_ID') ?: '');

function sendTelegram(string $message): bool {
    $token  = TELEGRAM_TOKEN;
    $chatId = TELEGRAM_CHAT_ID;
    $url    = "https://api.telegram.org/bot{$token}/sendMessage";

    $data = [
        'chat_id'    => $chatId,
        'text'       => $message,
        'parse_mode' => 'HTML',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log('[TELEGRAM] curl error: ' . $error);
        return false;
    }

    $result = json_decode($response, true);
    if (!($result['ok'] ?? false)) {
        error_log('[TELEGRAM] API error: ' . $response);
        return false;
    }

    return true;
}

function notifyNewRecharge(array $user, float $amount, string $notes): void {
    $msg = "💰 <b>NUEVA RECARGA — Cedeka WC</b>\n\n"
         . "👤 <b>Usuario:</b> " . htmlspecialchars($user['username']) . "\n"
         . "📧 <b>Email:</b> "   . htmlspecialchars($user['email'])    . "\n"
         . "💵 <b>Monto:</b> $"  . number_format($amount, 2, '.', ',') . " ARS\n"
         . "₵ <b>Cedenas:</b> "  . number_format($amount, 2, '.', ',') . " ₵\n"
         . "📝 <b>Referencia:</b> " . htmlspecialchars($notes)         . "\n\n"
         . "👉 <a href=\"https://cedeka-wc-production.up.railway.app/admin/index.php?page=recharges\">Ver en el panel →</a>";

    sendTelegram($msg);
}

function notifyRechargeApproved(array $user, float $amount): void {
    $msg = "✅ <b>RECARGA APROBADA</b>\n\n"
         . "👤 " . htmlspecialchars($user['username']) . "\n"
         . "₵ +" . number_format($amount, 2, '.', ',') . " Cedenas acreditadas";
    sendTelegram($msg);
}

function notifyNewBet(array $user, string $match, string $team, int $minute, float $amount): void {
    $msg = "🎯 <b>NUEVA APUESTA</b>\n\n"
         . "👤 " . htmlspecialchars($user['username']) . "\n"
         . "⚽ " . htmlspecialchars($match)            . "\n"
         . "🏳 " . htmlspecialchars($team)             . " — Min <b>{$minute}</b>\n"
         . "₵ "  . number_format($amount, 2, '.', ',') . " Cedenas";
    sendTelegram($msg);
}