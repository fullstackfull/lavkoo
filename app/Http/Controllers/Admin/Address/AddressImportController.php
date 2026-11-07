<?php

namespace App\Http\Controllers\Admin\Address;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Address\AddressImportRequest;
use App\Services\Address\AddressDataFiles;
use App\Services\Address\AddressImporter;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Loads the region and city lists that ship with the application: a preview that writes nothing, then the same import for real.
 * Nothing is ever deleted, and what an administrator changed (active or not, names) is kept.
 */
class AddressImportController extends Controller
{
    public const REPORT_KEY = 'address_import_report';

    public function __construct(private readonly AddressImporter $importer, private readonly AddressDataFiles $files)
    {
    }

    public function index(): View
    {
        return view('admin-views.address.import', ['files' => $this->files->describe(), 'report' => session(self::REPORT_KEY)]);
    }

    public function preview(): RedirectResponse
    {
        return $this->run(apply: false);
    }

    public function apply(AddressImportRequest $request): RedirectResponse
    {
        return $this->run(apply: true);
    }

    private function run(bool $apply): RedirectResponse
    {
        try {
            $report = $this->importer->import(
                regionsPath: config('address.seed.regions'),
                citiesPath: config('address.seed.cities'),
                apply: $apply,
                aliasesPath: config('address.seed.aliases'),
            );
        } catch (InvalidArgumentException $exception) {
            ToastMagic::error(translate('address_import_failed') . ': ' . e($exception->getMessage()));

            return redirect()->route('admin.address-system.import.index');
        }

        if ($apply) {
            ToastMagic::success(translate('address_import_applied'));
        } else {
            ToastMagic::info(translate('address_import_previewed'));
        }

        return redirect()->route('admin.address-system.import.index')->with(self::REPORT_KEY, $report);
    }
}
