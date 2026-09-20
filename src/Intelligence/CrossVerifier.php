<?php

namespace Fixzy\Kriptobot\Intelligence;

use Fixzy\Kriptobot\Intelligence\Contracts\NewsFetcherInterface;
use Exception;

class CrossVerifier
{
    /** @var NewsFetcherInterface[] */
    private array $fetchers = [];
    private int $timeWindowSeconds;

    /**
     * @param int $timeWindowSeconds Time window within which news is considered relevant (e.g. 7200 for 2 hours)
     */
    public function __construct(int $timeWindowSeconds = 7200)
    {
        $this->timeWindowSeconds = $timeWindowSeconds;
    }

    /**
     * Register an extractor source with the filtering engine
     */
    public function addFetcher(NewsFetcherInterface $fetcher): void
    {
        $this->fetchers[] = $fetcher;
    }

    /**
     * Perform cross-verification across all registered sources
     * * @param string $coinPair Example: 'BTC/USDT'
     * @return array Returns the data if valid, or an empty array if rejected
     */
    public function verifySignals(string $coinPair): array
    {
        $allNews = [];
        $sourcesHit = [];

        // 1. Collect all news from each module
        foreach ($this->fetchers as $fetcher) {
            try {
                $newsList = $fetcher->fetchLatestNews($coinPair);

                if (!empty($newsList)) {
                    foreach ($newsList as $news) {
                        // Only take news within the configured time window (e.g. last 2 hours)
                        if ((time() - $news['timestamp']) <= $this->timeWindowSeconds) {
                            $allNews[] = $news;
                            $sourcesHit[$fetcher->getSourceName()] = true; // Record the successful source
                        }
                    }
                }
            } catch (Exception $e) {
                // Ignore logically failed sources (graceful degradation)
                continue;
            }
        }

        // 2. Strict execution condition check
        $uniqueSourcesCount = count($sourcesHit);

        if ($uniqueSourcesCount >= 2) {
            // Condition met: at least 2 sources agree
            return [
                'status' => 'VERIFIED',
                'sources_count' => $uniqueSourcesCount,
                'sources_list' => array_keys($sourcesHit),
                'data' => $allNews
            ];
        }

        // Condition failed: fewer than 2 sources (reject the signal to avoid false positives)
        return [
            'status' => 'REJECTED',
            'sources_count' => $uniqueSourcesCount,
            'message' => 'Signal too weak. Requires at least 2 agreeing sources.'
        ];
    }
}