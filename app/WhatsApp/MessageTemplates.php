<?php

namespace App\WhatsApp;

use App\Contributions\ContributionLedger;
use App\Enums\MinistryRole;
use App\Models\Ministration;
use App\Models\SpecialContribution;
use App\Reports\WorshipReports;
use Carbon\CarbonImmutable;

/**
 * Ready-to-send WhatsApp texts. Uses WhatsApp formatting (*bold*, _italic_).
 * Contribution messages never include individual members' balances.
 */
class MessageTemplates
{
    public function __construct(protected WorshipReports $reports, protected ContributionLedger $ledger) {}

    protected function team(): string
    {
        return (string) config('worship.team_name');
    }

    public function roster(CarbonImmutable $month): string
    {
        $lines = ['*'.$this->team().'*', '*Ministering roster: '.$month->format('F Y').'*', ''];

        foreach ($this->reports->services($month)->groupBy(fn (Ministration $s) => $s->date->startOfWeek()->format('Y-m-d')) as $services) {
            foreach ($services as $s) {
                $lines[] = '*'.$s->date->format('D j M').'* · '.$s->displayTitle().' ('.$s->timeRange().')';
                $lines[] = '  Praise: '.($s->praiseLead()->name ?? 'TBA').' | Worship: '.($s->worshipLead()->name ?? 'TBA');
                $backups = $s->backups()->pluck('name')->implode(', ');
                if ($backups) {
                    $lines[] = '  Backups: '.$backups;
                }
                if ($s->uniform) {
                    $lines[] = '  Uniform: '.$s->uniform->name;
                }
            }
            $lines[] = '';
        }

        $lines[] = 'Please confirm your slots. God bless! 🙏';

        return implode("\n", $lines);
    }

    public function meeting(Ministration $s): string
    {
        $s->loadMissing(['members', 'uniform']);

        $lines = [
            '📢 *'.$s->displayTitle().'*',
            '🗓 '.$s->date->format('l, j F Y'),
            '🕒 '.$s->timeRange(),
        ];

        foreach (MinistryRole::leads() as $role) {
            $lines[] = '🎤 '.$role->label().': '.($s->leadFor($role)->name ?? 'TBA');
        }

        $backups = $s->backups()->pluck('name')->implode(', ');
        if ($backups) {
            $lines[] = '🎶 Backups: '.$backups;
        }

        if ($s->uniform) {
            $lines[] = '👔 Uniform: '.$s->uniform->name.' (top: '.$s->uniform->top.'; ladies: '.$s->uniform->ladies_bottom.'; men: '.$s->uniform->men_bottom.')';
        }

        if ($s->notes) {
            $lines[] = '';
            $lines[] = $s->notes;
        }

        $lines[] = '';
        $lines[] = '— '.$this->team();

        return implode("\n", $lines);
    }

    public function uniforms(CarbonImmutable $month): string
    {
        $lines = ['👔 *Uniform timetable: '.$month->format('F Y').'*', ''];

        foreach ($this->reports->uniformRows($month) as $row) {
            $lines[] = '*'.$row['day_num'].' '.$row['month_short'].' ('.substr((string) $row['day'], 0, 3).')* · '.$row['service'].': '.$row['uniform'];
        }

        $used = $this->reports->uniformsUsed($month);
        if ($used->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '*What to wear*';
            foreach ($used as $u) {
                $lines[] = '• *'.$u->name.'*: top '.$u->top.'; ladies '.$u->ladies_bottom.'; men '.$u->men_bottom;
            }
        }

        $lines[] = '';
        $lines[] = '— '.$this->team();

        return implode("\n", $lines);
    }

    public function arising(SpecialContribution $special): string
    {
        $status = $this->ledger->specialStatus($special);

        $lines = ['🙏 *'.$special->title.'*'];

        if ($special->description) {
            $lines[] = $special->description;
        }

        $lines[] = '';
        $lines[] = $special->amount_per_member
            ? 'Contribution: *'.ContributionLedger::money($special->amount_per_member).' per member*'
            : 'Any amount is welcome.';

        if ($special->due_date) {
            $lines[] = 'Deadline: *'.$special->due_date->format('l, j F Y').'*';
        }

        if ($status['collected'] > 0) {
            $lines[] = 'Collected so far: '.ContributionLedger::money($status['collected'])
                .($status['expected'] > 0 ? ' of '.ContributionLedger::money($status['expected']) : '')
                .' ('.$status['paid_count'].' members have paid)';
        }

        $lines[] = '';
        $lines[] = 'Thank you for your generosity. God bless! — '.$this->team();

        return implode("\n", $lines);
    }

    /** Year totals only; never individual members' balances. */
    public function contributionsSummary(int $year): string
    {
        $totals = $this->ledger->yearTotals($year);
        $grid = $this->ledger->monthlyGrid($year);
        $rate = $grid['rate'];
        $upToDate = collect($grid['rows'])->filter(fn ($r) => $r['arrears'] <= 0)->count();

        return implode("\n", [
            '💰 *Contributions update: '.$year.'*',
            '',
            'Monthly contribution: '.ContributionLedger::money($rate->monthly_amount).' · Registration: '.ContributionLedger::money($rate->registration_fee).' (once a year)',
            '',
            'Received this year: *'.ContributionLedger::money($totals['total']).'*',
            '• Monthly: '.ContributionLedger::money($totals['byType']['monthly'] ?? 0),
            '• Registration: '.ContributionLedger::money($totals['byType']['registration'] ?? 0),
            '• Arising: '.ContributionLedger::money($totals['byType']['special'] ?? 0),
            '',
            $upToDate.' of '.count($grid['rows']).' members are up to date. Check your own statement with the treasurer.',
            '',
            'Thank you for your faithfulness! — '.$this->team(),
        ]);
    }
}
