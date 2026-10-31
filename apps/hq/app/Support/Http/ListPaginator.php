<?php

namespace App\Support\Http;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * A length-aware paginator that also tells the screen which page size the person picked, the query keys it
 * pages by, and whether "All" hit the ceiling. Built only by {@see ListPage}.
 */
class ListPaginator extends LengthAwarePaginator
{
    /** @var string the size the person picked: '20', '100', '200', '500' or 'all' */
    public string $perPageOption = '20';

    public string $perPageName = 'per_page';

    /** The full number of matching rows; differs from total() only when "All" hit the ceiling. */
    public int $fullTotal = 0;

    public int $ceiling = 0;

    public function capped(): bool
    {
        return $this->fullTotal > $this->total();
    }

    /**
     * The paging facts without the rows, for a screen that ships its rows as a separate prop.
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        $array = $this->toArray();
        unset($array['data'], $array['links']);

        return $array;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return parent::toArray() + [
            'page_name' => $this->getPageName(),
            'per_page_name' => $this->perPageName,
            'per_page_option' => $this->perPageOption,
            'per_page_options' => ListPage::OPTIONS,
            'full_total' => $this->fullTotal,
            'capped' => $this->capped(),
            'ceiling' => $this->ceiling,
        ];
    }
}
