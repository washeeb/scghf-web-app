{{--
    The shell around a CMS-rendered email body.

    Layout, not content: every word a reader sees comes from the template in the
    database or from the settings layer, per CLAUDE.md's CMS rule. Nothing here
    is a sentence somebody might want to change.

    Table-based and inline-styled on purpose. Outlook and most Android mail
    clients still strip <style> blocks and ignore flexbox, and this project's
    readers are disproportionately on low-end Android handsets.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f5;">
@if ($preheader)
    {{-- Inbox preview text. Hidden in the body; without it clients show whatever
         text comes first, which is usually a URL. --}}
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $preheader }}</div>
@endif

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                   style="max-width:600px;background-color:#ffffff;border-radius:8px;">
                <tr>
                    <td style="padding:24px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.6;color:#18181b;">
                        {!! $bodyHtml !!}
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 24px 24px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;color:#71717a;">
                        <hr style="border:none;border-top:1px solid #e4e4e7;margin:0 0 16px;">
                        {{ setting('organisation.legal_name', setting('general.site_name', '')) }}<br>
                        {{ setting('contact.address', '') }}<br>
                        @if ($unsubscribeUrl)
                            <a href="{{ $unsubscribeUrl }}" style="color:#71717a;">Unsubscribe</a>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
