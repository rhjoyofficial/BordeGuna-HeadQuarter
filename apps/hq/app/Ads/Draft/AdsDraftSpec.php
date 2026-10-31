<?php

namespace App\Ads\Draft;

use InvalidArgumentException;

/** One campaign draft: one ad set, one ad. The budget is a decimal string in `currency`, never a float. */
final readonly class AdsDraftSpec
{
    public const OBJECTIVES = ['OUTCOME_SALES', 'OUTCOME_TRAFFIC', 'OUTCOME_AWARENESS', 'OUTCOME_ENGAGEMENT'];

    /**
     * @param  list<string>  $countries  ISO country codes
     * @param  array<string, string>  $claimProofs  claim key => the evidence reference a person recorded for it
     */
    public function __construct(
        public string $name,
        public string $objective,
        public string $dailyBudget,
        public string $currency,
        public string $pageId,
        public string $linkUrl,
        public string $primaryText,
        public string $headline,
        public ?string $description = null,
        public ?string $imageHash = null,
        public array $countries = ['BD'],
        public array $claimProofs = [],
    ) {
        if (! in_array($objective, self::OBJECTIVES, true)) {
            throw new InvalidArgumentException('The objective must be one of '.implode(', ', self::OBJECTIVES).'.');
        }
        if (preg_match('/^\d+(\.\d{1,2})?$/', $dailyBudget) !== 1) {
            throw new InvalidArgumentException('The daily budget must be a decimal amount such as 5.00.');
        }
        if (preg_match('/^\d{5,20}$/', $pageId) !== 1) {
            throw new InvalidArgumentException('The Page id must be digits.');
        }
        if (! str_starts_with($linkUrl, 'https://')) {
            throw new InvalidArgumentException('The link must be an https URL.');
        }
        if (trim($name) === '' || trim($primaryText) === '' || trim($headline) === '') {
            throw new InvalidArgumentException('A draft needs a name, a primary text and a headline.');
        }
    }

    /** @return list<string> the copy fields, for the language and claims checks */
    public function copy(): array
    {
        return array_values(array_filter([$this->primaryText, $this->headline, $this->description], static fn (?string $t) => $t !== null && trim($t) !== ''));
    }
}
