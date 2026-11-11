<?php

namespace App\Http\Controllers;

use App\Contributions\ContributionLedger;
use App\Exports\TableExport;
use App\Finance\BalanceSheet;
use App\Models\CashEntry;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

/**
 * Balance sheet (PDF / Excel), member payment receipts, uploaded expense
 * receipts, and one ZIP with all of it for a period.
 */
class FinanceExportController extends ExportController
{
    public function balance(Request $request, string $format): Response
    {
        $sheet = $this->sheet($request);

        if ($format === 'xlsx') {
            return Excel::download($this->cashbookExport($sheet), 'cash-book-'.$sheet->filename().'.xlsx');
        }

        return response($this->balancePdf($sheet), 200, $this->pdfHeaders('balance-sheet-'.$sheet->filename().'.pdf'));
    }

    /** A receipt for one member payment. */
    public function receipt(Payment $payment): Response
    {
        $payment->load(['member', 'specialContribution']);

        return $this->pdf('exports.receipts', [
            'title' => 'Payment receipt',
            'subtitle' => BalanceSheet::receiptNumber($payment),
            'payments' => collect([$payment]),
        ], 'portrait', BalanceSheet::receiptNumber($payment).'.pdf');
    }

    /** The scanned receipt attached to an expense / income entry. */
    public function attachment(CashEntry $entry): Response
    {
        abort_unless($entry->hasReceipt(), 404);

        return Storage::disk('local')->download((string) $entry->receipt_path, $this->attachmentName($entry));
    }

    /** Everything for the period: balance sheet, member receipts, expense receipts, cash book. */
    public function zip(Request $request): Response
    {
        $sheet = $this->sheet($request);
        $payments = $sheet->payments();
        $path = tempnam(sys_get_temp_dir(), 'receipts');

        $zip = new ZipArchive;
        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the ZIP file.');
        }

        $zip->addFromString('balance-sheet.pdf', $this->balancePdf($sheet));
        $zip->addFromString('cash-book.xlsx', Excel::raw($this->cashbookExport($sheet), ExcelFormat::XLSX));

        if ($payments->isNotEmpty()) {
            $zip->addFromString('member-receipts.pdf', $this->pdfContent('exports.receipts', [
                'title' => 'Member receipts',
                'subtitle' => $sheet->label().' · '.$payments->count().' '.Str::plural('payment', $payments->count()),
                'payments' => $payments,
            ], 'portrait'));
        }

        foreach ($sheet->cashEntries() as $entry) {
            if ($entry->hasReceipt()) {
                $folder = $entry->isIncome() ? 'income-receipts' : 'expense-receipts';
                $zip->addFromString($folder.'/'.$this->attachmentName($entry), (string) Storage::disk('local')->get((string) $entry->receipt_path));
            }
        }

        $zip->close();

        return response()->download($path, 'receipts-'.$sheet->filename().'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    protected function sheet(Request $request): BalanceSheet
    {
        $month = (int) $request->query('month', '0');

        return BalanceSheet::for((int) $request->query('year', (string) now()->year), $month >= 1 && $month <= 12 ? $month : null);
    }

    protected function balancePdf(BalanceSheet $sheet): string
    {
        return $this->pdfContent('exports.balance', [
            'title' => 'Balance sheet',
            'subtitle' => $sheet->label(),
            'summary' => $sheet->summary(),
            'funds' => $sheet->funds(),
            'entries' => $sheet->entries(),
        ], 'portrait');
    }

    protected function cashbookExport(BalanceSheet $sheet): TableExport
    {
        $rows = $sheet->entries()->map(fn (array $r) => [
            'date' => $r['date']->format('d M Y'),
            'category' => $r['category'],
            'description' => $r['description'],
            'party' => $r['party'],
            'in' => $r['in'] ? $r['amount'] : '',
            'out' => $r['in'] ? '' : $r['amount'],
            'balance' => round($r['balance'], 2),
            'method' => $r['method'],
            'reference' => $r['reference'],
            'receipt' => $r['receipt'],
        ])->all();

        $currency = ContributionLedger::currency();

        return new TableExport('Cash book '.$sheet->label(), [
            'date' => 'Date', 'category' => 'Category', 'description' => 'Description', 'party' => 'From / to',
            'in' => "Money in ({$currency})", 'out' => "Money out ({$currency})", 'balance' => "Balance ({$currency})",
            'method' => 'Method', 'reference' => 'Reference', 'receipt' => 'Receipt',
        ], $rows);
    }

    protected function attachmentName(CashEntry $entry): string
    {
        $ext = pathinfo((string) ($entry->receipt_name ?: $entry->receipt_path), PATHINFO_EXTENSION) ?: 'jpg';

        return $entry->entry_date->format('Y-m-d').'-'.Str::slug(Str::limit($entry->description, 40, '')).'-'.$entry->id.'.'.strtolower($ext);
    }

    /** @return array<string, string> */
    protected function pdfHeaders(string $filename): array
    {
        return ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"'];
    }
}
