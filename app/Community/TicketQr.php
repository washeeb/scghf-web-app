<?php

declare(strict_types=1);

namespace App\Community;

use App\Models\IssuedTicket;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * The square on the ticket.
 *
 * It encodes the door URL for the code, so a steward's phone camera opens
 * the check-in screen for exactly this ticket — signed in, permissioned,
 * one tap. The code is printed under it for the steward whose camera will
 * not focus, and the door screen takes it typed.
 *
 * SVG, because it is text: no image library on the server, and it scales
 * on a phone screen held up to a scanner. `chillerlan/php-qrcode` is
 * already here for the admin's two-factor setup.
 */
final class TicketQr
{
    public function svg(IssuedTicket $ticket): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'svgAddXmlHeader' => false,
            'svgUseFillAttributes' => true,
        ]);

        return (new QRCode($options))->render(self::doorUrl($ticket));
    }

    public static function doorUrl(IssuedTicket $ticket): string
    {
        return route('filament.admin.pages.door', ['code' => $ticket->code]);
    }
}
