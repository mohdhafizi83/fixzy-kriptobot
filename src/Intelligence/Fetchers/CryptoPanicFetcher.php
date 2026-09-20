<?php

namespace Fixzy\Kriptobot\Intelligence\Fetchers;

use Fixzy\Kriptobot\Intelligence\Contracts\NewsFetcherInterface;
use GuzzleHttp\Client;
use Exception;

class CryptoPanicFetcher implements NewsFetcherInterface
{
    private string $apiKey;
    private Client $httpClient;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->httpClient = new Client([
            'base_uri' => 'https://cryptopanic.com/api/v1/',
            'timeout'  => 10.0,
        ]);
    }

    public function getSourceName(): string
    {
        return 'CryptoPanic';
    }

    public function fetchLatestNews(string $coinPair): array
    {
        $currency = explode('/', $coinPair)[0];

        // SAFETY GUARD: If the API Key is empty, return an empty array.
        // The CrossVerifier engine will ignore this source automatically.
        if (empty($this->apiKey)) {
            return [];
        }

        try {
            $response = $this->httpClient->request('GET', 'posts/', [
                'query' => [
                    'auth_token' => $this->apiKey,
                    'currencies' => $currency,
                    'kind' => 'news',
                    'filter' => 'important'
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $newsList = [];

            if (isset($data['results'])) {
                foreach ($data['results'] as $item) {
                    $newsList[] = [
                        'source' => $this->getSourceName(),
                        'title' => $item['title'],
                        'timestamp' => strtotime($item['created_at'])
                    ];
                }
            }

            return $newsList;

        } catch (Exception $e) {
            // If the API server is down, throw an error.
            // The CrossVerifier engine (built earlier) already has a try-catch
            // block that will catch this error and continue to other sources
            // without causing Fixzy Kriptobot to crash.
            throw new Exception("Failed to extract news from CryptoPanic: " . $e->getMessage());
        }
    }
    
    // The getSimulatedNews() function has been completely removed
}