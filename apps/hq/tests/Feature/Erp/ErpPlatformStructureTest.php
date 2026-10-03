<?php

use App\Connectors\Capabilities;
use App\Erp\ErpConnector;
use App\Erp\ErpPlatform;
use App\Erp\ErpRegistry;
use App\Gateway\Credentials\CredentialVault;
use App\Models\Business;
use App\Models\IntegrationCredential;
use Database\Seeders\DomainSeeder;
use Symfony\Component\Finder\Finder;

/*
 | docs/101 §11 ruling 9.2: "ErpPlatform: extract the interface NOW; core logic depends on the interface,
 | not on ErpConnector." These hold it: the concrete client is named only inside app/Erp (the ERP
 | contract tests construct it directly, which is what they test), the registry hands back the
 | interface, and the `erp.read` capability's contract is the interface.
 */

it('names ErpConnector nowhere outside app/Erp, in code or in a docblock type', function () {
    $offenders = [];
    foreach (['app', 'config', 'routes', 'database', 'bootstrap'] as $dir) {
        foreach (Finder::create()->files()->in(base_path($dir))->name('*.php') as $file) {
            $relative = $dir.'/'.str_replace('\\', '/', $file->getRelativePathname());
            if (str_starts_with($relative, 'app/Erp/')) {
                continue;
            }
            if (preg_match('/\bErpConnector\b/', $file->getContents()) === 1) {
                $offenders[] = $relative;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('makes ErpConnector the ErpPlatform, with every interface method public on it', function () {
    expect(is_subclass_of(ErpConnector::class, ErpPlatform::class))->toBeTrue()
        ->and(interface_exists(ErpPlatform::class))->toBeTrue()
        ->and(Capabilities::contract(Capabilities::ERP_READ))->toBe(ErpPlatform::class)
        ->and(ErpRegistry::ADAPTER)->toBe(ErpConnector::class)
        // The contract versions are the interface's: the concrete class inherits them unchanged.
        ->and(ErpConnector::SALES_ORDERS_CONTRACT)->toBe(ErpPlatform::SALES_ORDERS_CONTRACT)
        ->and(ErpConnector::ORDER_READS_CONTRACT)->toBe('hq-order-reads.v1')
        ->and(ErpConnector::SALES_ORDER_DRAFTS_CONTRACT)->toBe('hq-sales-order-drafts.v1')
        ->and(ErpConnector::SALES_ORDERS_CONTRACT)->toBe('hq-sales-orders.v1');

    $return = (new ReflectionMethod(ErpRegistry::class, 'for'))->getReturnType();
    expect((string) $return)->toBe(ErpPlatform::class);
});

it('hands back the interface from the registry for a business with an opened ERP token', function () {
    $this->seed(DomainSeeder::class);
    $business = Business::whereNotNull('erp_business_id')->firstOrFail();
    $credential = IntegrationCredential::create([
        'provider' => ErpRegistry::PROVIDER, 'account_ref' => $business->slug, 'credential_type' => 'token',
        'payload' => ['value' => 'tok'], 'status' => 'active',
    ]);

    $erp = app(CredentialVault::class)->open([['provider' => ErpRegistry::PROVIDER, 'account_ref' => $business->slug, 'credential_id' => $credential->id]],
        fn () => app(ErpRegistry::class)->for($business));

    expect($erp)->toBeInstanceOf(ErpPlatform::class);
});

it('keeps the model runtime away from the ERP interface as it is from the client', function () {
    expect(file_get_contents(app_path('Ai/ModelRuntime.php')))->not->toContain('ErpPlatform');
});
