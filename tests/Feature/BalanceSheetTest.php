<?php

use App\Enums\PaymentType;
use App\Finance\BalanceSheet;
use App\Models\CashEntry;
use App\Models\Member;
use App\Models\Payment;
use App\Models\SpecialContribution;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->member = Member::factory()->create(['name' => 'Grace W', 'joined_at' => '2025-01-01']);
    $this->fund = SpecialContribution::create(['title' => 'Uniform fund', 'amount_per_member' => 1000]);

    $pay = fn (PaymentType $type, int $amount, string $date, array $extra = []) => Payment::create([
        'member_id' => $this->member->id, 'type' => $type, 'year' => 2026, 'amount' => $amount, 'paid_on' => $date, 'method' => 'mpesa', ...$extra,
    ]);
    $pay(PaymentType::MONTHLY, 500, '2025-12-20', ['year' => 2025, 'month' => 12]); // last year -> brought forward
    $pay(PaymentType::MONTHLY, 500, '2026-01-05', ['month' => 1]);
    $pay(PaymentType::REGISTRATION, 1000, '2026-01-05');
    $pay(PaymentType::SPECIAL, 1000, '2026-02-10', ['special_contribution_id' => $this->fund->id]);

    CashEntry::create(['direction' => 'in', 'category' => 'Donation', 'description' => 'Pastor gift', 'amount' => 2000, 'entry_date' => '2026-02-01', 'method' => 'cash']);
    CashEntry::create(['direction' => 'out', 'category' => 'Uniforms', 'description' => 'Navy ties', 'amount' => 800, 'entry_date' => '2026-02-15', 'method' => 'mpesa', 'special_contribution_id' => $this->fund->id]);
});

test('the balance sheet adds money in, takes money out and carries the balance', function () {
    $s = BalanceSheet::for(2026)->summary();

    expect($s['opening'])->toBe(500.0)
        ->and($s['totalIn'])->toBe(4500.0)
        ->and($s['totalOut'])->toBe(800.0)
        ->and($s['closing'])->toBe(4200.0)
        ->and(collect($s['income'])->pluck('amount', 'label')->all())->toMatchArray([
            'Monthly contributions' => 500.0, 'Registration fees' => 1000.0, 'Arising: Uniform fund' => 1000.0, 'Donation' => 2000.0,
        ]);

    expect(BalanceSheet::for(2026)->entries()->last()['balance'])->toBe(4200.0)
        ->and(BalanceSheet::for(2026)->funds())->toBe([['title' => 'Uniform fund', 'collected' => 1000.0, 'spent' => 800.0, 'balance' => 200.0]])
        ->and(BalanceSheet::for(2026, 2)->summary()['opening'])->toBe(2000.0);
});

test('expenses are recorded with a receipt that can be downloaded', function () {
    $this->actingAs(chairman());

    Livewire::test('pages::finance.index')
        ->call('newEntry', 'out')
        ->set('category', 'Transport')->set('description', 'Matatu to the crusade')->set('amount', '1500')
        ->set('entryDate', '2026-03-01')->set('receipt', UploadedFile::fake()->image('ticket.jpg'))
        ->call('save')->assertHasNoErrors();

    $entry = CashEntry::where('category', 'Transport')->firstOrFail();
    Storage::disk('local')->assertExists($entry->receipt_path);
    $this->get(route('exports.attachment', $entry))->assertOk()->assertDownload();

    $entry->delete();
    Storage::disk('local')->assertMissing($entry->receipt_path);
});

test('balance sheet, receipts and the receipts zip download', function () {
    $this->actingAs(chairman());
    $entry = CashEntry::where('category', 'Uniforms')->first();
    Storage::disk('local')->put('cash-receipts/ties.jpg', 'jpeg');
    $entry->update(['receipt_path' => 'cash-receipts/ties.jpg', 'receipt_name' => 'ties.jpg']);

    $this->get(route('exports.balance', ['format' => 'pdf', 'year' => 2026]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('exports.balance', ['format' => 'xlsx', 'year' => 2026]))->assertOk()->assertDownload('cash-book-2026.xlsx');
    $this->get(route('exports.receipt', Payment::first()))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $response = $this->get(route('exports.receipts', ['year' => 2026]))->assertOk()->assertDownload('receipts-2026.zip');
    $zip = new ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());
    $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i));
    expect($names)->toContain('balance-sheet.pdf', 'cash-book.xlsx', 'member-receipts.pdf', 'expense-receipts/2026-02-15-navy-ties-'.$entry->id.'.jpg');
});

test('the balance sheet page shows the totals', function () {
    $this->actingAs(chairman())
        ->get(route('finance', ['year' => 2026]))
        ->assertOk()->assertSee('Balance in hand')->assertSee('Navy ties')->assertSee('Uniform fund');
});
