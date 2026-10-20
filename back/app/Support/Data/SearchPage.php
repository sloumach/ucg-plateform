<?php

namespace App\Support\Data;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final readonly class SearchPage extends DataTransferObject
{
    public const SCAN_LIMIT = 100;

    /** @param list<array{id:string,label:string,reference:?string,parent_id:?string}> $items */
    public function __construct(public array $items, public ?string $after, public int $examined) {}

    /** @template T of Model
     * @param Collection<int,T> $candidates
     * @param callable(T):array{id:string,label:string,reference:?string,parent_id:?string} $project */
    public static function scan(Collection $candidates, string $term, int $size, callable $project, string $key = 'id'): self
    {
        $items = [];
        $after = null;
        $examined = 0;
        $term = mb_strtolower($term);
        foreach ($candidates as $record) {
            if ($examined >= self::SCAN_LIMIT || count($items) >= min(20, max(1, $size))) {
                break;
            }
            $row = $project($record);
            $after = (string) $record->getAttribute($key);
            $examined++;
            if (str_starts_with(mb_strtolower($row['label']), $term) || ($row['reference'] !== null && str_starts_with(mb_strtolower($row['reference']), $term))) {
                $items[] = $row;
            }
        }

        return new self($items, $candidates->count() > $examined ? $after : null, $examined);
    }
}
