<?php

namespace App\Support\Data;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class DashboardFeed extends DataTransferObject
{
    /** @param list<array<string,mixed>> $items
     * @param array<string,int> $meta */
    public function __construct(public array $items, public array $meta) {}

    /** @template T
     * @param LengthAwarePaginator<int,T> $page
     * @param Closure(T):array<string,mixed> $project */
    public static function fromPage(LengthAwarePaginator $page, Closure $project): self
    {
        return new self(array_values(array_map($project, $page->items())), ['total' => $page->total(),
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()]);
    }
}
