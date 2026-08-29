<?php

namespace App\Filament\AvatarProviders;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

class InitialsAvatarProvider implements AvatarProvider
{
    /** @var list<string> */
    private const BACKGROUNDS = [
        '#2563EB',
        '#4F46E5',
        '#7C3AED',
        '#BE123C',
        '#C2410C',
        '#047857',
        '#475569',
    ];

    public function get(Model $record): string
    {
        $name = trim(Filament::getNameForDefaultAvatar($record));
        $initials = $this->initials($name);
        $background = $this->background($name);
        $escapedInitials = htmlspecialchars($initials, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128" role="img">
  <rect width="128" height="128" rx="64" fill="{$background}"/>
  <text x="64" y="65" fill="#FFFFFF" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif" font-size="46" font-weight="600" text-anchor="middle" dominant-baseline="central">{$escapedInitials}</text>
</svg>
SVG;

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    private function initials(string $name): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($name)) ?: '';

        if ($normalized === '') {
            return '?';
        }

        $parts = preg_split('/[\s_-]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) > 1) {
            return mb_strtoupper(
                mb_substr($parts[0], 0, 1).mb_substr($parts[array_key_last($parts)], 0, 1)
            );
        }

        return mb_strtoupper(mb_substr($parts[0], 0, 2));
    }

    private function background(string $name): string
    {
        $hashPrefix = substr(hash('sha256', mb_strtolower($name)), 0, 8);
        $index = hexdec($hashPrefix) % count(self::BACKGROUNDS);

        return self::BACKGROUNDS[$index];
    }
}
