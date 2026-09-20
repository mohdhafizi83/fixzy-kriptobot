<?php

namespace Fixzy\Kriptobot\Intelligence\Contracts;

interface NewsFetcherInterface
{
    /**
     * Returns the name of the news source (e.g. 'CryptoPanic')
     */
    public function getSourceName(): string;

    /**
     * Extract the latest news for a specific coin pair.
     * * @param string $coinPair Example: 'BTC' or 'BTC/USDT'
     * @return array List of articles: [['title' => '...', 'timestamp' => 16123...], ...]
     */
    public function fetchLatestNews(string $coinPair): array;
}