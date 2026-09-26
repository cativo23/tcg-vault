<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\Collection\Services\CollectionCsvExporter;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CollectionExportController extends Controller
{
    public function __invoke(CollectionCsvExporter $exporter): StreamedResponse
    {
        $owner = auth()->user()->username ?? 'collection';
        $filename = 'tcg-vault-'.$owner.'-'.now()->toDateString().'.csv';

        return response()->streamDownload(function () use ($exporter) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                throw new RuntimeException('Could not open the response stream for the CSV export.');
            }
            $exporter->write($out);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
