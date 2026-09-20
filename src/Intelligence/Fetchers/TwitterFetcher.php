<?php

namespace Fixzy\Kriptobot\Intelligence\Fetchers;

use Fixzy\Kriptobot\Intelligence\Contracts\NewsFetcherInterface;
use GuzzleHttp\Client;
use Exception;

class TwitterFetcher implements NewsFetcherInterface
{
    private string $bearerToken;
    private Client $httpClient;

    /**
     * @param string $bearerToken Bearer Token from the Twitter Developer Portal
     */
    public function __construct(string $bearerToken)
    {
        $this->bearerToken = $bearerToken;
        
        $this->httpClient = new Client([
            'base_uri' => 'https://api.twitter.com/2/',
            'timeout'  => 10.0,
            'headers'  => [
                'Authorization' => "Bearer {$this->bearerToken}",
                'Accept'        => 'application/json',
            ]
        ]);
    }

    public function getSourceName(): string
    {
        return 'Twitter/X';
    }

    public function fetchLatestNews(string $coinPair): array
    {
        // Extract the symbol (e.g. BTC/USDT -> BTC)
        $currency = explode('/', $coinPair)[0];
        
        if (empty($this->apiKey)) {
            return []; 
        }        

        try {

            // Build the search query. Example: "$BTC OR #BTC -is:retweet lang:en"
            // We exclude retweets to reduce repeated data (spam)
            $searchQuery = "\${$currency} OR #{$currency} -is:retweet lang:en";

            // Call the Twitter API v2 Recent Search endpoint
            $response = $this->httpClient->request('GET', 'tweets/search/recent', [
                'query' => [
                    'query'        => $searchQuery,
                    'max_results'  => 20, // Limit to the 20 most recent tweets
                    'tweet.fields' => 'created_at',
                    'sort_order'   => 'recency'
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $newsList = [];

            if (isset($data['data']) && is_array($data['data'])) {
                foreach ($data['data'] as $tweet) {
                    $newsList[] = [
                        'source'    => $this->getSourceName(),
                        'title'     => $this->cleanText($tweet['text']),
                        'timestamp' => strtotime($tweet['created_at'])
                    ];
                }
            }

            return $newsList;

        } catch (Exception $e) {
            throw new Exception("Failed to extract data from Twitter/X: " . $e->getMessage());
        }
    }

    /**
     * Clean tweet text of URL links or newline characters (optional)
     */
    private function cleanText(string $text): string
    {
        // Strip t.co links for cleaner text when sent to the AI
        $text = preg_replace('/https?:\/\/t\.co\/[a-zA-Z0-9]+/', '', $text);
        return trim($text);
    }
}