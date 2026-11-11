<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Meeting minutes as a PDF: details, attendance register, minutes, action items. */
class MeetingExportController extends ExportController
{
    public function __invoke(Meeting $meeting): Response
    {
        $meeting->load(['chair:id,name', 'minuteTaker:id,name', 'attendances.member:id,name', 'actions.member:id,name']);

        return $this->pdf('exports.meeting', [
            'title' => $meeting->title,
            'subtitle' => $meeting->typeLabel().' · '.$meeting->date->format('l, j F Y'),
            'meeting' => $meeting,
        ], 'portrait', 'minutes-'.$meeting->date->format('Y-m-d').'-'.Str::slug($meeting->title).'.pdf');
    }
}
