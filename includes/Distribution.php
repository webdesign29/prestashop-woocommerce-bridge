<?php
/** Build-time distribution channel. No stored setting, key or request can switch it. */
namespace WD29\Bridge;

final class Distribution
{
    // Marketplace packaging changes this literal in a copy, never in the direct build.
    const CHANNEL = 'direct';

    public static function isMarketplace(): bool
    {
        return self::CHANNEL === 'woo-marketplace';
    }
}
