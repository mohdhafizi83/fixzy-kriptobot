<?php

namespace Fixzy\Kriptobot\Intelligence\Fetchers;

use Fixzy\Kriptobot\Intelligence\Contracts\NewsFetcherInterface;
use GuzzleHttp\Client;
use Exception;

class TelegramFetcher implements NewsFetcherInterface
{
    private string $botToken;
    private Client $httpClient;

    /**
     * @param string $botToken API token from @BotFather (e.g. '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11')
     */
    public function __construct(string $botToken)
    {
        $this->botToken = $botToken;
        $this->httpClient = new Client([
            'base_uri' => "https://api.telegram.org/bot{$this->botToken}/",
            'timeout'  => 10.0,
        ]);
    }

    public function getSourceName(): string
    {
        return 'Telegram (Internal Channel)';
    }

    public function fetchLatestNews(string $coinPair): array
    {
        // Extract the symbol (e.g. BTC/USDT -> BTC)
        $currency = explode('/', $coinPair)[0];
        
        if (empty($this->apiKey)) {
            return []; 
        }

        try {

            // Use the getUpdates method (suitable for a Cron Job)
            // Note: For large-scale production, a Webhook is recommended instead.
            $response = $this->httpClient->request('GET', 'getUpdates', [
                'query' => [
                    'limit' => 20, // Fetch the 20 most recent messages
                    'allowed_updates' => json_encode(['channel_post', 'message'])
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $newsList = [];

            if (isset($data['result']) && is_array($data['result'])) {
                foreach ($data['result'] as $update) {
                    // Messages can arrive via 'message' (group/personal) or 'channel_post' (channel)
                    $messageObj = $update['channel_post'] ?? $update['message'] ?? null;
                    
                    if ($messageObj && isset($messageObj['text'])) {
                        $text = $messageObj['text'];
                        
                        // Basic filter: only take messages that mention the coin we are looking for (e.g. "BTC")
                        if (stripos($text, $currency) !== false) {
                            $newsList[] = [
                                'source'    => $this->getSourceName(),
                                'title'     => $this->cleanText($text),
                                'timestamp' => $messageObj['date']
                            ];
                        }
                    }
                }
            }

            // Sort from newest to oldest
            usort($newsList, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);

            return $newsList;

        } catch (Exception $e) {
            throw new Exception("Failed to extract data from Telegram: " . $e->getMessage());
        }
    }

    /**
     * Clean the text of emojis or unwanted characters (optional)
     */
    private function cleanText(string $text): string
    {
        // For now we return the original text, but it's ready for pre-processing if needed
        return trim($text);
    }
}