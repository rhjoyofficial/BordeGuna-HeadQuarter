<?php

namespace App\Observe\Retention;

/**
 * One high-volume detail table under retention: how its rows are grouped and summed into a daily
 * aggregate, and the SQL condition (over the alias `t`) that keeps a row forever.
 *
 * Every string here is written in `RetentionPolicy` and never comes from input or configuration.
 */
final readonly class DetailTable
{
    /**
     * @param  list<string>  $dimensions  columns the rows are grouped by
     * @param  array<string, string>  $extraMeasures  name => aggregate SQL over `t`, stored in `measures`
     */
    public function __construct(
        public string $table,
        public int $keepDays,
        public array $dimensions,
        public ?string $durationColumn,
        public ?string $costExpression,
        public ?string $tokensInColumn,
        public ?string $tokensOutColumn,
        public array $extraMeasures,
        public string $keepCondition,
    ) {}

    /** The grouping as a JSON object with the columns in their declared order. */
    public function dimensionSql(): string
    {
        $pairs = [];
        foreach ($this->dimensions as $column) {
            $pairs[] = "'{$column}', t.{$column}";
        }

        return 'jsonb_build_object('.implode(', ', $pairs).')';
    }

    public function measuresSql(): string
    {
        if ($this->extraMeasures === []) {
            return 'NULL::jsonb';
        }

        $pairs = [];
        foreach ($this->extraMeasures as $name => $expression) {
            $pairs[] = "'{$name}', {$expression}";
        }

        return 'jsonb_build_object('.implode(', ', $pairs).')';
    }

    public function sumSql(?string $column): string
    {
        // Typed, because an untyped NULL in INSERT ... SELECT is text to PostgreSQL.
        return $column === null ? 'NULL::bigint' : "sum(t.{$column})";
    }

    public function costSql(): string
    {
        return $this->costExpression === null ? 'NULL::numeric' : "sum({$this->costExpression})";
    }
}
