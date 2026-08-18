<?php
require_once __DIR__ . '/../includes/config.php';

/**
 * Envía un mensaje a un usuario de Telegram utilizando la API de Telegram Bots.
 * 
 * @param string $chatId ID de Telegram del usuario (chat_id).
 * @param string $message Mensaje a enviar.
 * @return bool True si se envió correctamente, false de lo contrario.
 */
function enviarMensajeTelegram($chatId, $message) {
    if (empty($chatId) || TELEGRAM_BOT_TOKEN === 'TU_TELEGRAM_BOT_TOKEN_AQUI') {
        // Si no se ha configurado el token, registrar en logs locales para depuración
        error_log("[Telegram Debug] Intento de envío a chat ID '$chatId' sin Token: $message");
        return false;
    }

    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'Markdown'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Evitar problemas locales con certificados SSL en XAMPP

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        return true;
    } else {
        error_log("[Telegram Error] Error enviando mensaje a $chatId. Código HTTP: $httpCode. Respuesta: $response");
        return false;
    }
}
?>
