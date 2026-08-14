<?php

declare(strict_types=1);

namespace App\Matomo\Api\Events;

final class TourChallengesCollecting
{
    /**
     * @param  list<array{id: string, name: string, description: string, isCompleted: bool, isSkipped: bool, url: string}>  $challenges
     */
    public function __construct(public array $challenges) {}
}
