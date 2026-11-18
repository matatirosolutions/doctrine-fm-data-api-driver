<?php

declare(strict_types=1);

namespace MSDev\DoctrineFMDataAPIDriver\Platform;

use Doctrine\DBAL\Platforms\Keywords\KeywordList;

/**
 * Reserved words only matter when generating schema SQL, which this driver doesn't do.
 */
class FMKeywords extends KeywordList
{
    protected function getKeywords(): array
    {
        return [];
    }
}
