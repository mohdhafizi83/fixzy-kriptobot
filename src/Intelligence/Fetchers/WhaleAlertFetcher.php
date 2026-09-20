<?php

namespace Fixzy\Kriptobot\Intelligence\Fetchers;

use Fixzy\Kriptobot\Intelligence\Contracts\NewsFetcherInterface;
use GuzzleHttp\Client;
use Exception;

class WhaleAlertFetcher implements NewsFetcherInterface
{
    private string $apiKey;
    private Client $httpClient;

    /**
     * @param string $apiKey API Key from the Whale Alert platform (https://whale-alert.io/)
     */
    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        
        $this->httpClient = new Client([
            'base_uri' => 'https://api.whale-alert.io/v1/',
            'timeout'  => 10.0,
        ]);
    }

    public function getSourceName(): string
    {
        return 'Whale Alert (On-Chain Data)';
    }

    public function fetchLatestNews(string $coinPair): array
    {
        // Extract the symbol and lowercase it because the Whale Alert API uses lowercase format (e.g. 'btc')
        $currency = strtolower(explode('/', $coinPair)[0]);
        
        if (empty($this->apiKey)) {
            return []; 
        }

        try {

            // Look for transactions within the last 2 hours
            $startTime = time() - 7200;

            // Call the Whale Alert API
            $response = $this->httpClient->request('GET', 'transactions', [
                'query' => [
                    'api_key'   => $this->apiKey,
                    'currency'  => $currency,
                    'min_value' => 1000000, // Smart filter: only take transactions over $1 million (real whales)
                    'start'     => $startTime
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $newsList = [];

            if (isset($data['transactions']) && is_array($data['transactions'])) {
                foreach ($data['transactions'] as $tx) {
                    // Build a structured sentence so the AI can easily understand the transaction context
                    $amount = number_format($tx['amount']);
                    $amountUsd = number_format($tx['amount_usd']);
                    $from = ucfirst($tx['from']['owner'] ?? 'Unknown Wallet');
                    $to = ucfirst($tx['to']['owner'] ?? 'Unknown Wallet');

                    $title = "[ON-CHAIN WHALE ALERT] {$amount} " . strtoupper($currency) . " (Worth $ {$amountUsd}) was transferred from {$from} to {$to}.";

                    $newsList[] = [
                        'source'    => $this->getSourceName(),
                        'title'     => $title,
                        'timestamp' => $tx['timestamp']
                    ];
                }
            }

            return $newsList;

        } catch (Exception $e) {
            throw new Exception("Failed to extract data from Whale Alert: " . $e->getMessage());
        }
    }
}