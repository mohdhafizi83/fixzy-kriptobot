<?php

namespace Fixzy\Kriptobot\Intelligence\Fetchers;

use Fixzy\Kriptobot\Intelligence\Contracts\NewsFetcherInterface;
use GuzzleHttp\Client;
use DOMDocument;
use DOMXPath;
use Exception;

class BlockonomiFetcher implements NewsFetcherInterface
{
    private Client $httpClient;

    public function __construct()
    {
        $this->httpClient = new Client([
            'base_uri' => 'https://blockonomi.com/',
            'timeout'  => 15.0,
            'headers'  => [
                // Impersonate a real Chrome web browser to avoid firewall (WAF) blocking
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            ]
        ]);
    }

    public function getSourceName(): string
    {
        return 'Blockonomi Aggregator';
    }

    public function fetchLatestNews(string $coinPair): array
    {
        // Extract the symbol (e.g. BTC/USDT -> BTC)
        $currency = strtoupper(explode('/', $coinPair)[0]);
        $currencyFullName = $this->getCryptoFullName($currency);
        
        $newsList = [];

        try {
            // 1. Download the full HTML file from the website
            $response = $this->httpClient->request('GET', 'crypto-news-aggregator/');
            $html = $response->getBody()->getContents();

            // 2. Prevent PHP from throwing warnings if the web HTML is not W3C-compliant
            libxml_use_internal_errors(true);
            
            // 3. Initialize the DOM parser
            $dom = new DOMDocument();
            $dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
            
            // 4. Smart XPath query
            // Look for <a> tags inside <h2>, <h3>, or elements with class 'post/item'
            // This is the common structure of WordPress/aggregator-based websites
            $nodes = $xpath->query('//h2/a | //h3/a | //div[contains(@class, "post")]//a | //div[contains(@class, "news")]//a');

            if ($nodes === false || $nodes->length === 0) {
                // If the site structure changed completely, return an empty array (fail safely)
                return [];
            }

            // 5. Extract text and filter by target coin
            foreach ($nodes as $node) {
                $title = trim($node->textContent);
                
                if (empty($title)) {
                    continue;
                }

                // Filter: only keep the news if the title contains "BTC" or "Bitcoin"
                if (stripos($title, $currency) !== false || stripos($title, $currencyFullName) !== false) {
                    $newsList[] = [
                        'source'    => $this->getSourceName(),
                        'title'     => $title,
                        // Aggregators rarely show exact times on the main HTML view.
                        // We use the scrape time (time()) as an estimate that this is the latest news.
                        'timestamp' => time() 
                    ];
                }
            }

            // Clear the libxml error memory
            libxml_clear_errors();

            return $newsList;

        } catch (Exception $e) {
            // Throw an Exception if the connection drops or times out.
            // CrossVerifier will catch this error and keep the engine running without crashing.
            throw new Exception("Failed to scrape HTML from Blockonomi: " . $e->getMessage());
        }
    }

    /**
     * Smart helper: translate a symbol to its full name for broader news capture
     */
    private function getCryptoFullName(string $symbol): string
    {
        $map = [
            'BTC'  => 'Bitcoin',
            'ETH'  => 'Ethereum',
            'BNB'  => 'Binance',
            'SOL'  => 'Solana',
            'XRP'  => 'Ripple',
            'ADA'  => 'Cardano',
            'DOGE' => 'Dogecoin'
        ];

        return $map[$symbol] ?? $symbol;
    }
}