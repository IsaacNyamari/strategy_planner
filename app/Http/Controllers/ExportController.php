<?php

namespace App\Http\Controllers;

use App\Contributions\ContributionLedger;
use App\Enums\MemberStatus;
use App\Exports\TableExport;
use App\Models\Member;
use App\Models\Payment;
use App\Models\SpecialContribution;
use App\Reports\WorshipReports;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF / Excel downloads: roster, uniform timetable, songs, members,
 * contributions (yearly grid, ledger, arising, member statements).
 */
class ExportController extends Controller
{
    public function __construct(
        protected WorshipReports $reports,
        protected ContributionLedger $ledger,
    ) {}

    public function members(Request $request, string $format): Response
    {
        $filter = (string) $request->query('filter', 'current');

        $members = Member::query()
            ->when(in_array($filter, ['current', 'active'], true), fn ($q) => $q->where('status', '!=', MemberStatus::INACTIVE->value))
            ->when($filter === 'confirmed', fn ($q) => $q->where('status', MemberStatus::ACTIVE->value))
            ->when($filter === 'probation', fn ($q) => $q->probationers())
            ->when($filter === 'praise', fn ($q) => $q->where('status', MemberStatus::ACTIVE->value)->where('can_lead_praise', true))
            ->when($filter === 'worship', fn ($q) => $q->where('status', MemberStatus::ACTIVE->value)->where('can_lead_worship', true))
            ->when($filter === 'inactive', fn ($q) => $q->where('status', MemberStatus::INACTIVE->value))
            ->orderBy('name')
            ->get();

        $rows = $members->map(fn (Member $m) => [
            'name' => $m->name,
            'gender' => $m->gender->label(),
            'part' => (string) $m->part,
            'phone' => $m->phoneDisplay(),
            'email' => (string) $m->email,
            'leads' => $m->leadLabel() ?? '',
            'status' => $m->status->label(),
            'status_key' => $m->status->value,
            'joined' => $m->joined_at?->format('d M Y') ?? '',
            'probation_ends' => $m->onProbation() ? ($m->probation_ends_at?->format('d M Y') ?? '') : '',
        ])->values()->all();

        $labels = ['current' => 'Current members', 'active' => 'Current members', 'confirmed' => 'Confirmed members', 'probation' => 'On probation', 'praise' => 'Praise leaders', 'worship' => 'Worship leaders', 'inactive' => 'Inactive members', 'all' => 'All members'];
        $name = 'members-'.now()->format('Y-m-d');

        if ($format === 'xlsx') {
            return Excel::download(new TableExport('Members', [
                'name' => 'Name', 'gender' => 'Gender', 'part' => 'Part / instrument', 'phone' => 'Phone', 'email' => 'Email',
                'leads' => 'Can lead', 'status' => 'Status', 'joined' => 'Joined', 'probation_ends' => 'Probation ends',
            ], $rows), "{$name}.xlsx");
        }

        return $this->pdf('exports.members', [
            'title' => 'Members',
            'subtitle' => ($labels[$filter] ?? 'Members').' · '.count($rows),
            'rows' => $rows,
            'stats' => [
                'total' => count($rows),
                'probation' => collect($rows)->where('status_key', 'probation')->count(),
                'female' => $members->filter(fn ($m) => $m->gender->value === 'female')->count(),
                'male' => $members->filter(fn ($m) => $m->gender->value === 'male')->count(),
            ],
        ], 'landscape', "{$name}.pdf");
    }

    public function contributions(Request $request, string $format): Response
    {
        $year = (int) $request->query('year', (string) now()->year);
        $grid = $this->ledger->monthlyGrid($year);
        $name = "monthly-contributions-{$year}";

        if ($format === 'xlsx') {
            $months = [];
            foreach (range(1, 12) as $m) {
                $months['m'.$m] = ContributionLedger::monthName($m, 'M');
            }

            $rows = array_map(fn (array $r) => [
                'name' => $r['member']->name,
                'registration' => (float) $r['registration_paid'],
                ...array_combine(
                    array_map(fn (int|string $m) => 'm'.$m, array_keys($r['months'])),
                    array_map(fn (array $c) => (float) $c['paid'], $r['months']),
                ),
                'paid' => $r['paid'],
                'arrears' => $r['arrears'],
            ], $grid['rows']);

            return Excel::download(new TableExport("Contributions {$year}", [
                'name' => 'Member', 'registration' => 'Registration', ...$months, 'paid' => 'Monthly paid', 'arrears' => 'Arrears',
            ], $rows), "{$name}.xlsx");
        }

        return $this->pdf('exports.contributions', [
            'title' => 'Monthly contributions',
            'subtitle' => (string) $year,
            'year' => $year,
            'grid' => $grid,
            'totals' => $this->ledger->yearTotals($year),
        ], 'landscape', "{$name}.pdf");
    }

    public function payments(Request $request, string $format): Response
    {
        $year = (int) $request->query('year', (string) now()->year);
        $rows = Payment::with(['member:id,name', 'specialContribution:id,title'])
            ->where('year', $year)->orderBy('paid_on')->orderBy('id')->get()
            ->map(fn (Payment $p) => [
                'date' => $p->paid_on->format('d M Y'),
                'member' => $p->member->name ?? '',
                'type' => $p->type->label(),
                'for' => $p->forLabel(),
                'amount' => (float) $p->amount,
                'method' => $p->method->label(),
                'reference' => (string) $p->reference,
                'notes' => (string) $p->notes,
            ])->values()->all();

        return Excel::download(new TableExport("Payments {$year}", [
            'date' => 'Date', 'member' => 'Member', 'type' => 'Type', 'for' => 'For', 'amount' => 'Amount ('.ContributionLedger::currency().')',
            'method' => 'Method', 'reference' => 'Reference', 'notes' => 'Notes',
        ], $rows), "payments-{$year}.xlsx");
    }

    public function special(SpecialContribution $special, string $format): Response
    {
        $status = $this->ledger->specialStatus($special);
        $name = 'arising-'.Str::slug($special->title);

        if ($format === 'xlsx') {
            $rows = array_map(fn (array $r) => [
                'name' => $r['member']->name,
                'expected' => $r['expected'],
                'paid' => $r['paid'],
                'balance' => $r['balance'],
                'status' => ucfirst($r['status']),
            ], $status['rows']);

            return Excel::download(new TableExport($special->title, [
                'name' => 'Member', 'expected' => 'Expected', 'paid' => 'Paid', 'balance' => 'Balance', 'status' => 'Status',
            ], $rows), "{$name}.xlsx");
        }

        return $this->pdf('exports.special', [
            'title' => $special->title,
            'subtitle' => 'Arising contribution'.($special->due_date ? ' · due '.$special->due_date->format('j M Y') : ''),
            'status' => $status,
        ], 'portrait', "{$name}.pdf");
    }

    public function statement(Request $request, Member $member): Response
    {
        $year = (int) $request->query('year', (string) now()->year);

        return $this->pdf('exports.statement', [
            'title' => 'Contribution statement',
            'subtitle' => $member->name.' · '.$year,
            'st' => $this->ledger->statement($member, $year),
        ], 'portrait', 'statement-'.Str::slug($member->name)."-{$year}.pdf");
    }

    public function roster(Request $request, string $format): Response
    {
        $month = WorshipReports::monthStart($request->query('month'));
        $rows = $this->reports->rosterRows($month);
        $name = 'ministers-roster-'.$month->format('Y-m');

        if ($format === 'xlsx') {
            return Excel::download(new TableExport(
                'Roster '.$month->format('M Y'),
                ['date' => 'Date', 'day' => 'Day', 'service' => 'Service', 'time' => 'Time', 'praise_lead' => 'Praise leader', 'worship_lead' => 'Worship leader', 'backups' => 'Backups', 'uniform' => 'Uniform', 'songs' => 'Songs'],
                $rows,
            ), "{$name}.xlsx");
        }

        return $this->pdf('exports.roster', [
            'title' => 'Ministers roster',
            'subtitle' => $month->format('F Y'),
            'rows' => $rows,
            'summary' => $this->reports->rosterSummary($month),
            'stats' => $this->reports->rosterStats($month),
        ], 'landscape', "{$name}.pdf");
    }

    public function uniforms(Request $request, string $format): Response
    {
        $month = WorshipReports::monthStart($request->query('month'));
        $rows = $this->reports->uniformRows($month);
        $name = 'uniform-timetable-'.$month->format('Y-m');

        if ($format === 'xlsx') {
            return Excel::download(new TableExport(
                'Uniforms '.$month->format('M Y'),
                ['date' => 'Date', 'day' => 'Day', 'service' => 'Service', 'time' => 'Time', 'uniform' => 'Uniform', 'top' => 'Top', 'ladies' => 'Ladies (dress/skirt)', 'men' => 'Men (trousers)'],
                $rows,
            ), "{$name}.xlsx");
        }

        return $this->pdf('exports.uniforms', [
            'title' => 'Uniform timetable',
            'subtitle' => $month->format('F Y'),
            'rows' => $rows,
            'uniforms' => $this->reports->uniformsUsed($month),
        ], 'landscape', "{$name}.pdf");
    }

    public function songs(Request $request, string $format): Response
    {
        $filters = $request->only('search', 'language', 'category');
        $songs = $this->reports->songs($filters);
        $rows = $this->reports->songRows($songs);
        $name = 'songs-and-hymns-'.now()->format('Y-m-d');

        if ($format === 'xlsx') {
            return Excel::download(new TableExport(
                'Songs & hymns',
                ['title' => 'Title', 'artist' => 'Artist / writer', 'language' => 'Language', 'category' => 'Category', 'key' => 'Key', 'tempo' => 'Tempo', 'time' => 'Time', 'theme' => 'Theme', 'lyrics' => 'Lyrics stored'],
                $rows,
            ), "{$name}.xlsx");
        }

        return $this->pdf('exports.songs', [
            'title' => 'Songs & hymns',
            'subtitle' => $songs->count().' songs'.($this->filterLabel($filters) ? ' · '.$this->filterLabel($filters) : ''),
            'rows' => $rows,
            'groups' => collect($rows)->groupBy('language'),
            // Lyric sheets: only lyrics the team stored (public domain or licensed).
            'lyricSongs' => $request->boolean('lyrics') ? $songs->filter(fn ($s) => filled($s->lyrics))->values() : collect(),
        ], 'portrait', "{$name}.pdf");
    }

    /**
     * Render a PDF and stamp "Page X of Y" on every page. Done on the canvas
     * (not in the template) so dompdf's PHP execution can stay disabled.
     *
     * @param  array<string, mixed>  $data
     */
    protected function pdf(string $view, array $data, string $orientation, string $filename): Response
    {
        $pdf = Pdf::loadView($view, $data)
            ->setPaper('a4', $orientation)
            ->setOption('isFontSubsettingEnabled', true); // embed only used glyphs: ~880 KB -> ~50 KB
        $pdf->render();

        $dompdf = $pdf->getDomPDF();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans') ?? 'Helvetica';
        $canvas = $dompdf->getCanvas();
        $canvas->page_text($canvas->get_width() - 90, $canvas->get_height() - 26, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7, [0.63, 0.63, 0.67]);

        return $pdf->download($filename);
    }

    /** @param array<string, mixed> $filters */
    protected function filterLabel(array $filters): string
    {
        return collect([
            filled($filters['language'] ?? null) ? ucfirst((string) $filters['language']) : null,
            filled($filters['category'] ?? null) ? ucfirst((string) $filters['category']) : null,
            filled($filters['search'] ?? null) ? '“'.$filters['search'].'”' : null,
        ])->filter()->implode(' · ');
    }
}
