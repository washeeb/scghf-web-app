<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Community\TicketQr;
use App\Models\IssuedTicket;
use App\Support\PageMeta;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * A ticket, on a phone.
 *
 * Signed URLs with no expiry: the link in the email has to work on the day
 * however long ago the order was placed, and it names one ticket that
 * cannot be guessed from another. The page carries the QR, the code, the
 * holder and the event; the SVG route is the same square on its own for
 * anybody who wants to save it.
 */
class TicketController extends Controller
{
    public function show(IssuedTicket $ticket): View
    {
        $ticket->load(['event', 'ticketType', 'registration']);

        return view('events.ticket', [
            'ticket' => $ticket,
            'event' => $ticket->event,
            'qr' => app(TicketQr::class)->svg($ticket),
            'meta' => PageMeta::site(__('Your ticket'), noindex: true),
        ]);
    }

    public function qr(IssuedTicket $ticket): Response
    {
        return response(app(TicketQr::class)->svg($ticket), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
