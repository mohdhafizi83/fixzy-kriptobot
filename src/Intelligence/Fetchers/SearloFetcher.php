<?php

namespace Fixzy\Kriptobot\Intelligence\Fetchers;

use Fixzy\Kriptobot\Intelligence\Contracts\NewsFetcherInterface;
use GuzzleHttp\Client;
use Exception;

class SearloFetcher implements NewsFetcherInterface
{
    private string $apiKey;
    private Client $httpClient;

    /**
     * @param string $apiKey API Key from the Searlo dashboard
     */
    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        
        // Guzzle client configuration for the Searlo API
        $this->httpClient = new Client([
            'base_uri' => 'https://api.searlo.com/', // Searlo API base path
            'timeout'  => 10.0,
        ]);
    }

    public function getSourceName(): string
    {
        return 'Google News (Searlo SERP)';
    }

    public function fetchLatestNews(string $coinPair): array
    {
        // Extract the symbol (e.g. BTC/USDT -> Bitcoin / BTC)
        $currency = explode('/', $coinPair)[0];
        // Build a search query specifically for Google News
        $searchQuery = "{$currency} crypto news";

        if (empty($this->apiKey)) {
            return []; 
        }

        try {

            // Call the Searlo Search endpoint (format subject to Searlo's latest documentation)
            $response = $this->httpClient->request('GET', 'search', [
                'query' => [
                    'api_key' => $this->apiKey,
                    'q'       => $searchQuery,
                    'engine'  => 'google_news', // Tell Searlo to scrape the Google News tab
                    'hl'      => 'en',          // English
                    'gl'      => 'us',          // United States geography (financial news hub)
                    'num'     => 10             // Limit to the top 10 news items
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $newsList = [];

            // Process the JSON response (structure based on the SERP API standard)
            if (isset($data['news_results']) && is_array($data['news_results'])) {
                foreach ($data['news_results'] as $item) {
                    $newsList[] = [
                        'source'    => $this->getSourceName() . " - " . ($item['source'] ?? 'Web'),
                        'title'     => trim($item['title']),
                        // Searlo SERP usually returns time as a string (e.g. "2 hours ago" or an ISO date)
                        // To be safe, if there is no exact timestamp, we record the time the bot fetched the data.
                        'timestamp' => isset($item['date']) ? strtotime($item['date']) : time()
                    ];
                }
            }

            return $newsList;

        } catch (Exception $e) {
            throw new Exception("Failed to extract data from Searlo (Google SERP): " . $e->getMessage());
        }
    }
}