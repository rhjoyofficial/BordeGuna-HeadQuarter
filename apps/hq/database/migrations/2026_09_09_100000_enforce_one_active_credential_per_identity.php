<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One ACTIVE credential per `(provider, account_ref)`, enforced by the database.
 *
 * `CredentialWriter` already replaces rather than accumulates, but application code is a promise
 * and an index is a guarantee: a future controller, console command or seeder that inserts an
 * active row directly cannot recreate the state where the vault had two identities to choose from
 * and no rule for choosing.
 *
 * `coalesce(account_ref, '')` because the column is nullable and PostgreSQL treats NULLs in a
 * unique index as distinct - without it, two rows with no account reference would both be allowed.
 * `deleted_at IS NULL` because the model soft-deletes and every read applies that scope, so the
 * index must describe exactly the set the vault queries.
 *
 * ⚠ ADDITIVE AND REVERSIBLE: it adds an index and touches no row. `down()` drops it.
 *
 * ⚠ It REFUSES rather than repairs. If a database already holds duplicate active rows, this throws
 * with the offending identities named and changes nothing: which key an operator meant to keep is
 * not a question a migration is entitled to answer, and revoking the wrong one silently would be
 * the same class of defect this index exists to prevent. Revoke the extras on the credentials
 * screen, then migrate again. The check runs BEFORE any DDL, because a failed statement inside a
 * PostgreSQL migration transaction poisons the transaction and turns the COMMIT into a silent
 * ROLLBACK while the migration is still recorded as run.
 */
return new class extends Migration
{
    private const INDEX = 'integration_credentials_one_active_per_identity';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('integration_credentials')) {
            return;
        }

        $duplicates = DB::select(
            "select provider, coalesce(account_ref, '') as account_ref, count(*) as active_rows
             from integration_credentials
             where status = 'active' and deleted_at is null
             group by provider, coalesce(account_ref, '')
             having count(*) > 1
             order by provider"
        );

        if ($duplicates !== []) {
            $named = implode(', ', array_map(
                static fn ($row) => $row->provider.'/'.($row->account_ref === '' ? 'no account' : $row->account_ref).' ('.$row->active_rows.' active)',
                $duplicates,
            ));

            throw new RuntimeException(
                "integration_credentials already holds more than one active credential for: {$named}. "
                .'Nothing was changed. Revoke the extra rows on the credentials screen so exactly one '
                .'stays active per provider/account, then run this migration again.'
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEX." ON integration_credentials (provider, coalesce(account_ref, ''))
             WHERE status = 'active' AND deleted_at IS NULL"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        }
    }
};
