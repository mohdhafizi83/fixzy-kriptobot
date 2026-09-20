<?php

namespace Fixzy\Kriptobot\Notifications;

use Exception;
use GuzzleHttp\Client;

class NotificationService
{
    private ?string $telegramToken;
    private ?string $telegramChatId;
    private Client $httpClient;

    public function __construct(?string $telegramToken = null, ?string $telegramChatId = null)
    {
        $this->telegramToken = $telegramToken;
        $this->telegramChatId = $telegramChatId;
        $this->httpClient = new Client(['timeout' => 10.0]);
    }

    public function setTelegramChatId(?string $chatId): void
    {
        $this->telegramChatId = $chatId;
    }

    /**
     * Send a notification to Telegram
     */
    public function sendTelegramAlert(string $message): bool
    {
        if (empty($this->telegramToken) || empty($this->telegramChatId)) {
            return false;
        }

        try {
            $url = "https://api.telegram.org/bot{$this->telegramToken}/sendMessage";
            $this->httpClient->post($url, [
                'json' => [
                    'chat_id' => $this->telegramChatId,
                    'text' => $message,
                    'parse_mode' => 'HTML'
                ]
            ]);
            return true;
        } catch (Exception $e) {
            // Silently log the error so the bot is not disrupted
            error_log("Telegram Alert Failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send an email notification (simulated)
     */
    public function sendEmailAlert(string $emailAddress, string $subject, string $body): bool
    {
        if (empty($emailAddress)) {
            return false;
        }

        // Simulation example. In production, use PHPMailer or an SMTP service such as SendGrid.
        $headers = "From: bot@kriptobot.local\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        
        return mail($emailAddress, $subject, $body, $headers);
    }
}
