<?php

namespace Fixzy\Kriptobot\Intelligence\Fetchers;

use Fixzy\Kriptobot\Intelligence\Contracts\NewsFetcherInterface;
use GuzzleHttp\Client;
use Exception;

class FinnhubFetcher implements NewsFetcherInterface
{
    private string $apiKey;
    private Client $httpClient;

    /**
     * @param string $apiKey API Key from the Finnhub portal (https://finnhub.io/)
     */
    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        
        $this->httpClient = new Client([
            'base_uri' => 'https://finnhub.io/api/v1/',
            'timeout'  => 10.0,
        ]);
    }

    public function getSourceName(): string
    {
        return 'TradFi Macroeconomics (Finnhub)';
    }

    public function fetchLatestNews(string $coinPair): array
    {
        
        if (empty($this->apiKey)) {
            return []; 
        }

        try {

            // Call the Finnhub API for the 'general' category (economy/stock market news)
            $response = $this->httpClient->request('GET', 'news', [
                'query' => [
                    'category' => 'general',
                    'token'    => $this->apiKey
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $newsList = [];

            // Smart keyword list that has a major impact on Crypto
            $macroKeywords = ['fed', 'cpi', 'inflation', 'interest rate', 'fomc', 'powell', 'unemployment', 'sec'];

            if (is_array($data)) {
                foreach ($data as $item) {
                    $headline = strtolower($item['headline']);
                    $summary = strtolower($item['summary']);
                    
                    // Smart Filter:
                    // We don't want ordinary company news (e.g. Apple selling iPhones).
                    // We only want news that touches macroeconomic keywords.
                    $isMacro = false;
                    foreach ($macroKeywords as $keyword) {
                        if (str_contains($headline, $keyword) || str_contains($summary, $keyword)) {
                            $isMacro = true;
                            break;
                        }
                    }

                    if ($isMacro) {
                        $newsList[] = [
                            'source'    => $this->getSourceName() . " - " . ($item['source'] ?? 'News'),
                            'title'     => trim($item['headline']),
                            // Finnhub provides time as a UNIX timestamp ('datetime')
                            'timestamp' => $item['datetime'] 
                        ];
                    }

                    // Limit to the 5 most relevant macro news items to avoid overloading the AI
                    if (count($newsList) >= 5) break;
                }
            }

            return $newsList;

        } catch (Exception $e) {
            throw new Exception("Failed to extract data from Finnhub: " . $e->getMessage());
        }
    }
}