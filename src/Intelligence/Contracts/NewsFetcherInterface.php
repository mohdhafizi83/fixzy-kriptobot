<?php

namespace Fixzy\Kriptobot\Intelligence\Contracts;

/**
 * Class NewsFetcherInterface - part of the Fixzy Kriptobot project.
 * This file must retain this notice and its attribution to Fixzy Kriptobot (free use, attribution required - see LICENSE).
 */
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