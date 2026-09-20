<?php

namespace App\Briefing;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * The unsourced-number check (docs/48 M.1, docs/80 point 1): every figure in a narrative must be
 * traceable to an item of the bundle it was written from. It runs after narration and trusts no
 * prompt. A narrative with one unsourced figure fails, and the figure is named.
 *
 * ## The rule (`briefing.numbers.v1`), strict on purpose
 *
 * 1. **Every digit run is a figure**, wherever it stands: "45", "top5", "Q3", "3rd", "24/7" (two
 *    figures). There is no whitelist. Not for small counts, not for ordinals, not for a year: "1" and
 *    "2026" must be in the bundle like any other number. A narrator that wants to say "two shops"
 *    needs an item whose value is 2.
 * 2. **Equality is exact**, after normalisation only: thousands separators in the Western (1,234,567)
 *    and the South Asian (12,34,567) grouping are removed, and leading and trailing zeros do not
 *    count (1060 equals 1,060.00). Nothing is rounded: 1,060 does not match 1060.40, 3.1 does not
 *    match 3.06, and an item's value off by one does not match. The sign is ignored.
 * 3. **Nothing is computed.** A figure that is the sum, the difference, the share or the average of
 *    two items is unsourced unless the bundle itself carries that result as a metric. This is the
 *    point of the check: a number nobody can trace is refused even when it is arithmetically right.
 * 4. **Scale words multiply, and the product must still match exactly**: k, thousand, lakh or lac,
 *    crore, million, and হাজার, লাখ, লক্ষ, কোটি. "3.06k" is 3060; "3k" is 3000 and does not match 3060.
 * 5. **A percentage needs a percent item.** A figure followed by %, "percent" or শতাংশ matches only an
 *    item whose unit is `percent`, so a narrator cannot turn two counts into a share.
 * 6. **A currency figure needs a BDT item.** A figure with ৳, BDT, Tk, taka or টাকা beside it matches
 *    only an item whose unit is `BDT`. A bare figure may match an item of any unit.
 * 7. **Bangla digits (০ to ৯) are read as digits.** A digit of any other script is reported as
 *    unsourced, because the check cannot read it and will not pass what it cannot read.
 * 8. **Dates and times** are recognised as such and must equal a date or a time the bundle carries:
 *    the briefing date, yesterday's date, `generated_at`, any item's or source's `as_of`, or the value
 *    of a `datetime` item, all read in the bundle's timezone. Forms read: 2026-09-19, an ISO date
 *    with a time, 19 September 2026, 19 Sep, September 19, 2026, and 09:00 or 9:00 am. A date without
 *    a year matches on day and month. A date written any other way is read as plain figures and so
 *    fails unless those figures are items.
 * 9. **Spelled-out numbers.** The English words two to twenty and the tens to ninety are read as
 *    their values. A bare magnitude with no figure ("hundreds of orders", "thousands") is unsourced.
 *    "one" is not read, being a pronoun more often than a number, and ordinal words are not read.
 * 10. **Identifiers.** An item's `subject` (a SKU, an approval's uuid, a job's name) that contains
 *    digits may be quoted whole, and its digits are then not figures. Only whole, exact occurrences.
 *
 * ## What it does not prove
 *
 * It proves each figure exists in the bundle. It does not prove the sentence around it says the
 * right thing about it: "3 orders were cancelled" passes if any item has the value 3. It does not
 * read Bangla number words or Bangla month names. Those are limits of a deterministic check, stated
 * here so nobody takes a pass for more than it is.
 */
final class NarrativeNumberCheck
{
    public const RULE = 'briefing.numbers.v1';

    private const BANGLA_DIGITS = ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'];

    /** The power of ten each scale word stands for. */
    private const SCALES = [
        'k' => 3, 'thousand' => 3, 'হাজার' => 3,
        'lakh' => 5, 'lakhs' => 5, 'lac' => 5, 'lacs' => 5, 'লাখ' => 5, 'লক্ষ' => 5,
        'million' => 6, 'crore' => 7, 'crores' => 7, 'কোটি' => 7,
    ];

    private const WORDS = [
        'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
        'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17,
        'eighteen' => 18, 'nineteen' => 19, 'twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fifty' => 50, 'sixty' => 60,
        'seventy' => 70, 'eighty' => 80, 'ninety' => 90,
    ];

    private const MONTHS = [
        'january' => 1, 'jan' => 1, 'february' => 2, 'feb' => 2, 'march' => 3, 'mar' => 3, 'april' => 4, 'apr' => 4, 'may' => 5,
        'june' => 6, 'jun' => 6, 'july' => 7, 'jul' => 7, 'august' => 8, 'aug' => 8, 'september' => 9, 'sep' => 9, 'sept' => 9,
        'october' => 10, 'oct' => 10, 'november' => 11, 'nov' => 11, 'december' => 12, 'dec' => 12,
    ];

    private string $text = '';

    private string $work = '';

    /** @var list<array<string, mixed>> */
    private array $figures = [];

    public function check(string $narrative, EvidenceBundle $bundle): NarrativeCheckResult
    {
        $this->text = strtr($narrative, self::BANGLA_DIGITS);
        $this->work = $this->text;
        $this->figures = [];

        $this->identifiers($bundle);
        $this->datesAndTimes($bundle);
        $this->numbers($bundle);
        $this->unreadableDigits();
        $this->words($bundle);

        usort($this->figures, static fn (array $a, array $b): int => $a['_at'] <=> $b['_at']);

        return new NarrativeCheckResult(array_map(static function (array $f): array {
            unset($f['_at']);

            return $f;
        }, $this->figures));
    }

    // ── identifiers ────────────────────────────────────────────────────────────────────────────

    private function identifiers(EvidenceBundle $bundle): void
    {
        $subjects = [];
        foreach ($bundle->items as $item) {
            $s = $item->subject;
            // Long enough to be a name, with a digit and with something that is not a digit: a bare
            // number can never be whitelisted by calling it a subject.
            if ($s !== null && mb_strlen($s) >= 4 && preg_match('/\d/', $s) === 1 && preg_match('/[^\d\s.,]/u', $s) === 1) {
                $subjects[strtr($s, self::BANGLA_DIGITS)][] = $item->id;
            }
        }

        uksort($subjects, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($subjects as $subject => $ids) {
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote((string) $subject, '/').'(?![\p{L}\p{N}])/u';
            $this->each($pattern, function (array $m) use ($ids): void {
                $this->record($m[0][0], $m[0][1], 'identifier', null, array_values(array_unique($ids)), null);
            });
        }
    }

    // ── dates and times ────────────────────────────────────────────────────────────────────────

    private function datesAndTimes(EvidenceBundle $bundle): void
    {
        $tz = $bundle->timezone;
        $instants = [$bundle->generatedAt];
        $holders = ['bundle.generated_at'];

        foreach ($bundle->sources as $source) {
            if ($source->asOf !== null) {
                $instants[] = $source->asOf;
                $holders[] = "source:{$source->key}";
            }
        }
        foreach ($bundle->items as $item) {
            if ($item->asOf !== null) {
                $instants[] = $item->asOf;
                $holders[] = $item->id;
            }
            if ($item->unit === 'datetime' && is_string($item->value)) {
                try {
                    $instants[] = CarbonImmutable::parse($item->value);
                    $holders[] = $item->id;
                } catch (Throwable) {
                    // An unreadable datetime item backs nothing.
                }
            }
        }

        /** @var array<string, list<string>> $dates Y-m-d => holders */
        $dates = [$bundle->briefingDate => ['bundle.briefing_date'], $bundle->yesterday['date'] => ['bundle.yesterday']];
        /** @var array<string, list<string>> $minutes "Y-m-d H:i" => holders */
        $minutes = [];
        /** @var array<string, list<string>> $times "H:i" => holders */
        $times = [];
        foreach ($instants as $i => $instant) {
            $local = $instant->setTimezone($tz);
            $dates[$local->toDateString()][] = $holders[$i];
            $minutes[$local->format('Y-m-d H:i')][] = $holders[$i];
            $times[$local->format('H:i')][] = $holders[$i];
        }

        // An ISO date with a time, with or without an offset.
        $this->each('/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?/', function (array $m) use ($tz, $minutes): void {
            try {
                $key = CarbonImmutable::parse($m[0][0], $tz)->setTimezone($tz)->format('Y-m-d H:i');
            } catch (Throwable) {
                $key = '';
            }
            $this->record($m[0][0], $m[0][1], 'datetime', $key, $minutes[$key] ?? [], 'No date and time in the bundle equals this one.');
        });

        $this->each('/\d{4}-\d{2}-\d{2}/', function (array $m) use ($dates): void {
            $this->record($m[0][0], $m[0][1], 'date', $m[0][0], $dates[$m[0][0]] ?? [], 'No date in the bundle equals this one.');
        });

        $month = implode('|', array_keys(self::MONTHS));
        $written = function (array $m) use ($dates): void {
            $day = (int) $m['d'][0];
            $mon = self::MONTHS[strtolower($m['m'][0])];
            $year = isset($m['y']) && $m['y'][0] !== '' ? (int) $m['y'][0] : null;
            $holders = [];
            foreach ($dates as $date => $held) {
                [$y, $mo, $d] = array_map('intval', explode('-', $date));
                if ($d === $day && $mo === $mon && ($year === null || $year === $y)) {
                    array_push($holders, ...$held);
                }
            }
            $this->record($m[0][0], $m[0][1], 'date', sprintf('%s-%02d-%02d', $year ?? '....', $mon, $day), $holders, 'No date in the bundle equals this one.');
        };
        $this->each('/(?<![\p{L}\p{N}])(?<d>\d{1,2})(?:st|nd|rd|th)?\s+(?<m>'.$month.')\.?(?![\p{L}])(?:,?\s+(?<y>\d{4}))?(?!\d)/iu', $written);
        $this->each('/(?<![\p{L}])(?<m>'.$month.')\.?\s+(?<d>\d{1,2})(?:st|nd|rd|th)?(?![\p{L}\p{N}])(?:,?\s+(?<y>\d{4}))?(?!\d)/iu', $written);

        $this->each('/(?<![\d:])(?<h>[01]?\d|2[0-3]):(?<i>[0-5]\d)(?::[0-5]\d)?(?!\d)(?:\s*(?<ap>am|pm|a\.m\.|p\.m\.)(?![\p{L}]))?/iu', function (array $m) use ($times): void {
            $hour = (int) $m['h'][0];
            $ap = isset($m['ap']) ? strtolower(str_replace('.', '', $m['ap'][0])) : '';
            if ($ap === 'pm' && $hour < 12) {
                $hour += 12;
            } elseif ($ap === 'am' && $hour === 12) {
                $hour = 0;
            }
            $key = sprintf('%02d:%s', $hour, $m['i'][0]);
            $this->record($m[0][0], $m[0][1], 'time', $key, $times[$key] ?? [], 'No time in the bundle equals this one.');
        });
    }

    // ── numbers ────────────────────────────────────────────────────────────────────────────────

    private function numbers(EvidenceBundle $bundle): void
    {
        /** @var array<string, list<EvidenceItem>> $values normalised value => items */
        $values = [];
        foreach ($bundle->items as $item) {
            if (($n = $item->numericValue()) !== null) {
                $values[self::normalise(ltrim($n, '-'))][] = $item;
            }
        }

        $currency = '৳|BDT|Tk\.?|taka|টাকা';
        $scale = 'thousand|lakhs?|lacs?|crores?|million|হাজার|লাখ|লক্ষ|কোটি';
        $pattern = '/(?:(?<c1>'.$currency.')\s*)?'
            .'(?<n>\d{1,3}(?:,\d{3})+|\d{1,2}(?:,\d{2})*,\d{3}|\d+)(?<f>\.\d+)?'
            .'(?:(?<s1>k)(?![\p{L}\p{N}])|\s*(?<s2>'.$scale.')(?![\p{L}]))?'
            .'(?:\s*(?<p>%|percent|per\s+cent|শতাংশ))?'
            .'(?:\s*(?<c2>'.$currency.')(?![\p{L}]))?/iu';

        $this->each($pattern, function (array $m) use ($values): void {
            $word = strtolower(($m['s1'][0] ?? '') !== '' ? $m['s1'][0] : ($m['s2'][0] ?? ''));
            $zeros = $word === '' ? 0 : (self::SCALES[$word] ?? self::SCALES[mb_strtolower($word)] ?? 0);
            $normalised = self::scaled(str_replace(',', '', $m['n'][0]), ltrim($m['f'][0] ?? '', '.'), $zeros);

            $isPercent = ($m['p'][0] ?? '') !== '';
            $isMoney = ($m['c1'][0] ?? '') !== '' || ($m['c2'][0] ?? '') !== '';

            $candidates = array_values(array_filter($values[$normalised] ?? [], static fn (EvidenceItem $i): bool => match (true) {
                $isPercent => $i->unit === 'percent',
                $isMoney => $i->unit === 'BDT',
                default => true,
            }));

            $why = match (true) {
                $candidates !== [] => null,
                $isPercent => 'No percent item in the bundle has this value. A share of two items is not an item.',
                $isMoney => 'No BDT item in the bundle has this value.',
                default => 'No item in the bundle has this value.',
            };

            $this->record(trim($m[0][0]), $m[0][1], $isPercent ? 'percent' : ($isMoney ? 'money' : 'number'), $normalised,
                array_map(static fn (EvidenceItem $i): string => $i->id, $candidates), $why);
        });
    }

    private function unreadableDigits(): void
    {
        // Runs after `numbers()`, so every digit the check can read is already blanked out.
        $this->each('/\p{Nd}+/u', function (array $m): void {
            $this->record($m[0][0], $m[0][1], 'unreadable', null, [], 'Digits in a script the check does not read are never passed.');
        });
    }

    private function words(EvidenceBundle $bundle): void
    {
        $values = [];
        foreach ($bundle->items as $item) {
            if (($n = $item->numericValue()) !== null) {
                $values[self::normalise(ltrim($n, '-'))][] = $item->id;
            }
        }

        $this->each('/(?<![\p{L}\p{N}])(?<w>'.implode('|', array_keys(self::WORDS)).')(?![\p{L}\p{N}])/iu', function (array $m) use ($values): void {
            $value = (string) self::WORDS[strtolower($m['w'][0])];
            $this->record($m[0][0], $m[0][1], 'word', $value, $values[$value] ?? [], 'No item in the bundle has this value.');
        });

        $this->each('/(?<![\p{L}\p{N}])(?:dozens?|hundreds?|thousands?|lakhs?|lacs?|crores?|millions?|billions?)(?![\p{L}\p{N}])/iu', function (array $m): void {
            $this->record($m[0][0], $m[0][1], 'magnitude', null, [], 'A magnitude with no figure cannot be traced to any item.');
        });
    }

    // ── mechanics ──────────────────────────────────────────────────────────────────────────────

    /** Runs a pattern over what is still unread, hands each match to `$found`, and blanks it out. */
    private function each(string $pattern, callable $found): void
    {
        if (preg_match_all($pattern, $this->work, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) < 1) {
            return;
        }

        foreach ($matches as $m) {
            if ($m[0][0] === '') {
                continue;
            }
            $found($m);
            $this->work = substr_replace($this->work, str_repeat(' ', strlen($m[0][0])), $m[0][1], strlen($m[0][0]));
        }
    }

    /** @param  list<string>  $holders */
    private function record(string $figure, int $at, string $type, ?string $normalised, array $holders, ?string $why): void
    {
        $holders = array_values(array_unique($holders));
        $sourced = $holders !== [];

        $this->figures[] = [
            '_at' => $at,
            'figure' => $figure,
            'type' => $type,
            'normalised' => $normalised,
            'matched_item_ids' => $holders,
            'sourced' => $sourced,
            'why' => $sourced ? null : $why,
            'excerpt' => trim(mb_strcut($this->text, max(0, $at - 40), strlen($figure) + 80, 'UTF-8')),
        ];
    }

    /** A decimal written one way: no separators, no leading zeros, no trailing zeros. */
    public static function normalise(string $decimal): string
    {
        [$int, $frac] = array_pad(explode('.', str_replace(',', '', $decimal), 2), 2, '');
        $int = ltrim($int, '0');
        $frac = rtrim($frac, '0');

        return ($int === '' ? '0' : $int).($frac === '' ? '' : '.'.$frac);
    }

    /** `$int.$frac` times ten to the `$zeros`, by moving the point: no float is involved. */
    private static function scaled(string $int, string $frac, int $zeros): string
    {
        if ($zeros === 0) {
            return self::normalise($int.($frac === '' ? '' : '.'.$frac));
        }

        $frac = str_pad($frac, $zeros, '0');

        return self::normalise($int.substr($frac, 0, $zeros).'.'.substr($frac, $zeros));
    }
}
