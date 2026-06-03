<?php
// =============================================
// CEDEKA WC — Notificaciones Telegram
// =============================================

define('TELEGRAM_TOKEN',   $_ENV['TELEGRAM_TOKEN']   ?? getenv('TELEGRAM_TOKEN')   ?? '');
define('TELEGRAM_CHAT_ID', $_ENV['TELEGRAM_CHAT_ID'] ?? getenv('TELEGRAM_CHAT_ID') ?? '');

function sendTelegram(string $message): bool {
    $token  = TELEGRAM_TOKEN;
    $chatId = TELEGRAM_CHAT_ID;
    if (empty($token) || empty($chatId)) return false;

    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = ['chat_id' => $chatId, 'text' => $message, 'parse_mode' => 'HTML'];

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

    if ($error) { error_log('[TELEGRAM] '.$error); return false; }
    $result = json_decode($response, true);
    if (!($result['ok'] ?? false)) { error_log('[TELEGRAM] '.$response); return false; }
    return true;
}

function notifyNewRecharge(array $user, float $amount, string $notes): void {
    $msg = "💰 <b>NUEVA RECARGA — Cedeka WC</b>\n\n"
         . "👤 <b>Usuario:</b> ".htmlspecialchars($user['username'])."\n"
         . "📧 <b>Email:</b> ".htmlspecialchars($user['email'])."\n"
         . "💵 <b>Monto:</b> $".number_format($amount,2,'.',',')." ARS\n"
         . "₵ <b>Cedenas:</b> ".number_format($amount,2,'.',',')." ₵\n"
         . "📝 <b>Referencia:</b> ".htmlspecialchars($notes)."\n\n"
         . "👉 <a href=\"https://cedeka-wc-production.up.railway.app/admin/index.php?page=recharges\">Ver en el panel →</a>";
    sendTelegram($msg);
}

function notifyRechargeApproved(array $user, float $amount): void {
    sendTelegram("✅ <b>RECARGA APROBADA</b>\n\n"
        . "👤 ".htmlspecialchars($user['username'])."\n"
        . "₵ +".number_format($amount,2,'.',',')." Cedenas acreditadas");
}

function notifyNewBet(array $user, string $match, string $team, int $minute, float $amount): void {
    sendTelegram("🎯 <b>NUEVA APUESTA</b>\n\n"
        . "👤 ".htmlspecialchars($user['username'])."\n"
        . "⚽ ".htmlspecialchars($match)."\n"
        . "🏳 ".htmlspecialchars($team)." — Min <b>{$minute}</b>\n"
        . "₵ ".number_format($amount,2,'.',',')." Cedenas");
}

function notifyMatchWinners(string $matchName, array $winners, float $potTotal, float $commission): void {
    if (empty($winners)) {
        $msg = "📦 <b>PARTIDO RESUELTO — SIN GANADORES</b>\n\n"
             . "⚽ <b>Partido:</b> ".htmlspecialchars($matchName)."\n"
             . "💰 <b>Pozo total:</b> ₵".number_format($potTotal,2,'.',',')."\n\n"
             . "➡️ El pozo se acumula al siguiente partido.";
    } else {
        $prizeEach = round(($potTotal - $commission) / count($winners), 2);
        $winnerLines = '';
        foreach ($winners as $bet) {
            $username = htmlspecialchars($bet['username'] ?? ('ID #'.$bet['user_id']));
            $winnerLines .= "   🏆 <b>{$username}</b> — ".htmlspecialchars($bet['team'])." min <b>{$bet['minute']}</b> → +₵".number_format($prizeEach,2,'.',',')."\n";
        }
        $msg = "🎉 <b>PARTIDO RESUELTO — HAY GANADORES</b>\n\n"
             . "⚽ <b>Partido:</b> ".htmlspecialchars($matchName)."\n"
             . "👥 <b>Ganadores:</b> ".count($winners)."\n"
             . "💰 <b>Pozo total:</b> ₵".number_format($potTotal,2,'.',',')."\n"
             . "🏠 <b>Comisión:</b> ₵".number_format($commission,2,'.',',')."\n"
             . "🎁 <b>Premio por ganador:</b> ₵".number_format($prizeEach,2,'.',',')."\n\n"
             . "<b>Detalle:</b>\n"
             . $winnerLines;
    }
    sendTelegram($msg);
}