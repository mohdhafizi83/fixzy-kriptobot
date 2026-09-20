<?php

namespace Fixzy\Kriptobot\Intelligence\Fetchers;

use Fixzy\Kriptobot\Intelligence\Contracts\NewsFetcherInterface;
use GuzzleHttp\Client;
use Exception;

class DiscordFetcher implements NewsFetcherInterface
{
    private string $botToken;
    private string $channelId;
    private Client $httpClient;

    /**
     * @param string $botToken  Bot API token from the Discord Developer Portal
     * @param string $channelId ID of the specific channel to monitor (e.g. '10485739201928374')
     */
    public function __construct(string $botToken, string $channelId)
    {
        $this->botToken = $botToken;
        $this->channelId = $channelId;
        
        $this->httpClient = new Client([
            'base_uri' => 'https://discord.com/api/v10/',
            'timeout'  => 10.0,
            'headers'  => [
                'Authorization' => "Bot {$this->botToken}",
                'Accept'        => 'application/json',
            ]
        ]);
    }

    public function getSourceName(): string
    {
        return 'Discord (Alpha Group)';
    }

    public function fetchLatestNews(string $coinPair): array
    {
        // Extract the symbol (e.g. BTC/USDT -> BTC)
        $currency = explode('/', $coinPair)[0];
        
        if (empty($this->apiKey)) {
            return []; 
        }

        try {

            // Call the Discord API to fetch the last 20 messages from the channel
            $response = $this->httpClient->request('GET', "channels/{$this->channelId}/messages", [
                'query' => [
                    'limit' => 20
                ]
            ]);

            $messages = json_decode($response->getBody()->getContents(), true);
            $newsList = [];

            if (is_array($messages)) {
                foreach ($messages as $message) {
                    // Check if the message has text and mentions the coin we are looking for
                    if (isset($message['content']) && stripos($message['content'], $currency) !== false) {
                        $newsList[] = [
                            'source'    => $this->getSourceName(),
                            'title'     => trim($message['content']),
                            // Discord returns time in ISO8601 format; convert it to a UNIX timestamp
                            'timestamp' => strtotime($message['timestamp'])
                        ];
                    }
                }
            }

            // Sort from newest to oldest (just in case Discord changes the ordering)
            usort($newsList, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);

            return $newsList;

        } catch (Exception $e) {
            throw new Exception("Failed to extract data from Discord: " . $e->getMessage());
        }
    }
}