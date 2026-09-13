<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Concerns;

use App\Media\MediaLibrary;
use App\Models\Product;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Moves the file behind a digital product into the private library.
 *
 * Filament's upload lands on the `downloads` disk under `incoming/`; from
 * there it goes through `MediaLibrary::add(private: true)` — the one door
 * into the library, with its type sniffing and its safe filename — and the
 * product points at the resulting row. The staging copy is removed. A
 * product that stops being digital keeps its file: nothing is deleted by a
 * change of mind on a select.
 */
trait StoresDownloadFile
{
    protected function storeDownloadFile(): void
    {
        /** @var Product $product */
        $product = $this->getRecord();
        $state = $this->form->getRawState()['download_upload'] ?? null;
        $path = is_array($state) ? (reset($state) ?: null) : $state;

        if (! is_string($path) || $path === '' || ! Storage::disk('downloads')->exists($path)) {
            return;
        }

        $absolute = Storage::disk('downloads')->path($path);
        $upload = new UploadedFile($absolute, basename($path), Storage::disk('downloads')->mimeType($path) ?: null, null, true);

        try {
            $media = app(MediaLibrary::class)->add($upload, null, [], auth()->user(), private: true);
        } catch (RuntimeException $e) {
            Notification::make()->title(__('The file was not accepted'))->body($e->getMessage())->danger()->persistent()->send();
            Storage::disk('downloads')->delete($path);

            return;
        }

        $product->forceFill(['download_media_id' => $media->getKey()])->save();
        Storage::disk('downloads')->delete($path);
    }
}
