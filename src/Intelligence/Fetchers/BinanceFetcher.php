<?php

namespace Fixzy\Kriptobot\Intelligence\Fetchers;

use Fixzy\Kriptobot\Intelligence\Contracts\NewsFetcherInterface;
use GuzzleHttp\Client;
use Exception;

class BinanceFetcher implements NewsFetcherInterface
{
    private Client $httpClient;

    public function __construct()
    {
        // Go straight to the official public Binance website (Category: New Cryptocurrency Listing)
        $this->httpClient = new Client([
            'base_uri' => 'https://www.binance.com/',
            'timeout'  => 15.0,
            'headers'  => [
                // Critical: impersonate a real Google Chrome web browser
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept-Language' => 'en-US,en;q=0.9',
            ]
        ]);
    }

    public function getSourceName(): string
    {
        return 'Binance Official Announcement';
    }

    public function fetchLatestNews(string $coinPair): array
    {
        // Extract the symbol (e.g. BTC/USDT -> BTC)
        $currency = explode('/', $coinPair)[0];

        if (empty($this->apiKey)) {
            return []; 
        }

        try {
            // Call the Listing Announcement web page directly
            $response = $this->httpClient->request('GET', 'en/support/announcement/new-cryptocurrency-listing?c=48&navId=48');
            $html = $response->getBody()->getContents();

            $newsList = [];

            // Enterprise scraping technique: Binance stores the article data as JSON
            // inside the script with id "__APP_DATA" in their HTML code.
            // We use a Regular Expression (Regex) to capture that JSON array.
            if (preg_match('/id="__APP_DATA" type="application\/json">({.*?})<\/script>/is', $html, $matches)) {
                $appData = json_decode($matches[1], true);
                
                // Navigate into the structure of their internal data
                $articles = $appData['appState']['loader']['dataByRouteId']['d9b2']['catalogs'][0]['articles'] ?? [];

                foreach ($articles as $article) {
                    $title = $article['title'] ?? '';
                    
                    // Only take titles relevant to our coin
                    if (stripos($title, $currency) !== false) {
                        $newsList[] = [
                            'source'    => $this->getSourceName(),
                            'title'     => "[OFFICIAL ANNOUNCEMENT] " . trim($title),
                            // Binance provides releaseDate in milliseconds
                            'timestamp' => isset($article['releaseDate']) ? (int)($article['releaseDate'] / 1000) : time()
                        ];
                    }
                }
            }

            return $newsList;

        } catch (Exception $e) {
            // Error is caught safely without stopping the bot entirely
            throw new Exception("Failed to extract HTML data from Binance: " . $e->getMessage());
        }
    }
}