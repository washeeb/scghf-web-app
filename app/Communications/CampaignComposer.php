<?php

declare(strict_types=1);

namespace App\Communications;

use App\Models\Cause;
use App\Models\Media;
use App\Support\ThemeTokens;

/**
 * Turns a campaign's blocks into the HTML and text the sender uses.
 *
 * ── Six blocks, all of them email-safe ──────────────────────────────────────
 *
 * heading, paragraph, button, image, divider, appeal. Tables and inline
 * styles, because Outlook and most Android mail clients strip everything
 * else. The colours come from the theme tokens the site already uses, so a
 * newsletter looks like the site without anybody choosing a hex code.
 *
 * ── Compiled at save, not at send ───────────────────────────────────────────
 *
 * `body_html` stays the single thing the sender reads and the test send
 * shows. A campaign written as raw HTML before this existed still works,
 * because its `blocks` are empty and its body is left alone.
 */
final class CampaignComposer
{
    public const TYPES = ['heading', 'paragraph', 'button', 'image', 'divider', 'appeal'];

    /**
     * @param  list<array{type: string, data?: array<string, mixed>}>  $blocks
     * @return array{html: string, text: string}
     */
    public function compile(array $blocks): array
    {
        $html = [];
        $text = [];

        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? '');
            $data = (array) ($block['data'] ?? []);

            [$h, $t] = match ($type) {
                'heading' => $this->heading($data),
                'paragraph' => $this->paragraph($data),
                'button' => $this->button($data),
                'image' => $this->image($data),
                'divider' => ['<hr style="border:none;border-top:1px solid #E2E8E5;margin:24px 0;">', "\n— — —\n"],
                'appeal' => $this->appeal($data),
                default => ['', ''],
            };

            if ($h !== '') {
                $html[] = $h;
            }

            if ($t !== '') {
                $text[] = $t;
            }
        }

        return ['html' => implode("\n", $html), 'text' => trim(implode("\n\n", $text))];
    }

    /** @param array<string, mixed> $data @return array{0: string, 1: string} */
    private function heading(array $data): array
    {
        $text = trim((string) ($data['text'] ?? ''));

        return $text === ''
            ? ['', '']
            : ['<h2 style="font-family:Arial,Helvetica,sans-serif;font-size:22px;line-height:1.3;margin:24px 0 8px;color:'.$this->brand().';">'.e($text).'</h2>', strtoupper($text)];
    }

    /** @param array<string, mixed> $data @return array{0: string, 1: string} */
    private function paragraph(array $data): array
    {
        $text = trim((string) ($data['text'] ?? ''));

        if ($text === '') {
            return ['', ''];
        }

        $paragraphs = preg_split('/\n{2,}/', $text) ?: [];
        $html = collect($paragraphs)
            ->map(fn (string $p): string => '<p style="margin:0 0 16px;">'.nl2br(e(trim($p))).'</p>')
            ->implode("\n");

        return [$html, $text];
    }

    /** @param array<string, mixed> $data @return array{0: string, 1: string} */
    private function button(array $data): array
    {
        $label = trim((string) ($data['label'] ?? ''));
        $url = trim((string) ($data['url'] ?? ''));

        if ($label === '' || $url === '') {
            return ['', ''];
        }

        return [
            '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 24px;"><tr><td style="background-color:'.$this->accent().';border-radius:6px;">'
            .'<a href="'.e($url).'" style="display:inline-block;padding:12px 24px;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:bold;color:'.$this->onAccent().';text-decoration:none;">'.e($label).'</a>'
            .'</td></tr></table>',
            $label.': '.$url,
        ];
    }

    /** @param array<string, mixed> $data @return array{0: string, 1: string} */
    private function image(array $data): array
    {
        $media = ! empty($data['media_id']) ? Media::find($data['media_id']) : null;

        if ($media === null || ! $media->isPublishable()) {
            return ['', ''];
        }

        $alt = trim((string) ($data['alt'] ?? $media->alt_text ?? ''));
        $caption = trim((string) ($data['caption'] ?? ''));

        $html = '<img src="'.e($media->getUrl()).'" alt="'.e($alt).'" width="552" style="display:block;width:100%;max-width:552px;height:auto;border-radius:6px;margin:8px 0;">';

        if ($caption !== '') {
            $html .= '<p style="margin:4px 0 16px;font-size:13px;color:#5E706B;">'.e($caption).'</p>';
        }

        return [$html, $caption !== '' ? '['.$caption.']' : ''];
    }

    /** An appeal card: title, one line, progress, a button. @param array<string, mixed> $data @return array{0: string, 1: string} */
    private function appeal(array $data): array
    {
        $cause = ! empty($data['cause_id']) ? Cause::find($data['cause_id']) : null;

        if ($cause === null) {
            return ['', ''];
        }

        $url = route('causes.show', $cause);
        $raised = $cause->raisedAmount();
        $goal = $cause->goal ?? null;
        $percent = $goal !== null && $goal->isPositive() ? min(100, (int) floor($raised->toMinor() * 100 / max(1, $goal->toMinor()))) : null;

        $progress = $percent === null ? '' : sprintf(
            '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" style="margin:8px 0;"><tr><td style="background:#E2E8E5;border-radius:4px;height:8px;"><div style="width:%d%%;background:%s;height:8px;border-radius:4px;"></div></td></tr></table><p style="margin:0 0 8px;font-size:13px;color:#5E706B;">%s</p>',
            $percent,
            $this->accent(),
            e(__(':raised raised of :goal', ['raised' => $raised->format(), 'goal' => $goal->format()])),
        );

        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #E2E8E5;border-radius:8px;margin:16px 0;"><tr><td style="padding:16px;">'
            .'<h3 style="margin:0 0 6px;font-family:Arial,Helvetica,sans-serif;font-size:18px;color:'.$this->brand().';">'.e($cause->title).'</h3>'
            .($cause->summary ? '<p style="margin:0 0 8px;">'.e($cause->summary).'</p>' : '')
            .$progress
            .'<a href="'.e($url).'" style="font-weight:bold;color:'.$this->accent().';">'.e(__('Give to this appeal')).' →</a>'
            .'</td></tr></table>';

        $text = $cause->title."\n".($cause->summary ? $cause->summary."\n" : '').($percent === null ? '' : __(':raised raised of :goal', ['raised' => $raised->format(), 'goal' => $goal->format()])."\n").$url;

        return [$html, $text];
    }

    private function brand(): string
    {
        return app(ThemeTokens::class)->value('brand-primary');
    }

    private function accent(): string
    {
        return app(ThemeTokens::class)->value('brand-secondary');
    }

    private function onAccent(): string
    {
        return app(ThemeTokens::class)->value('text-on-secondary');
    }
}
