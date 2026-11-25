<?php

namespace WelcomeOnboarding\Support;

use App\Traits\EnvironmentWriterTrait;
use Illuminate\Support\Arr;
use JsonException;

class SettingsRepository
{
    use EnvironmentWriterTrait;

    private const ENV_KEY = 'WELCOME_ONBOARDING_SETTINGS';

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $defaults = config('welcome-onboarding.defaults', []);
        $stored = $this->getStoredSettings();

        return array_merge($defaults, is_array($stored) ? $stored : []);
    }

    /**
     * @param  array<mixed, mixed>  $data
     */
    public function save(array $data): void
    {
        $settings = $this->normalize($data);
        $encoded = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->writeToEnvironment([self::ENV_KEY => $encoded]);
        config(['welcome-onboarding.stored' => $settings]);
    }

    /**
     * @param  array<mixed, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalize(array $data): array
    {
        $defaults = config('welcome-onboarding.defaults', []);
        $extraLinks = $this->normalizeLinks(Arr::wrap($data['extra_links'] ?? []));
        $translations = collect(Arr::wrap($data['translations'] ?? []))
            ->map(function ($translation) {
                $locale = str((string) ($translation['locale'] ?? ''))->lower()->trim()->replace('_', '-')->toString();

                return [
                    'locale' => $locale,
                    'subject' => trim((string) ($translation['subject'] ?? '')),
                    'heading' => trim((string) ($translation['heading'] ?? '')),
                    'intro_text' => trim((string) ($translation['intro_text'] ?? '')),
                    'setup_button_label' => trim((string) ($translation['setup_button_label'] ?? '')),
                    'setup_button_url' => trim((string) ($translation['setup_button_url'] ?? '')),
                    'welcome_link_label' => trim((string) ($translation['welcome_link_label'] ?? '')),
                    'first_login_steps' => trim((string) ($translation['first_login_steps'] ?? '')),
                    'closing_text' => trim((string) ($translation['closing_text'] ?? '')),
                    'extra_links' => $this->normalizeLinks(Arr::wrap($translation['extra_links'] ?? [])),
                ];
            })
            ->filter(fn (array $translation) => $translation['locale'] !== '')
            ->values()
            ->all();

        return array_merge($defaults, [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'subject' => trim((string) ($data['subject'] ?? '')),
            'heading' => trim((string) ($data['heading'] ?? '')),
            'intro_text' => trim((string) ($data['intro_text'] ?? '')),
            'setup_button_label' => trim((string) ($data['setup_button_label'] ?? '')),
            'setup_button_url' => trim((string) ($data['setup_button_url'] ?? '')),
            'welcome_url' => trim((string) ($data['welcome_url'] ?? '')),
            'welcome_link_label' => trim((string) ($data['welcome_link_label'] ?? '')),
            'discord_url' => trim((string) ($data['discord_url'] ?? '')),
            'community_url' => trim((string) ($data['community_url'] ?? '')),
            'support_url' => trim((string) ($data['support_url'] ?? '')),
            'first_login_steps' => trim((string) ($data['first_login_steps'] ?? '')),
            'closing_text' => trim((string) ($data['closing_text'] ?? '')),
            'extra_links' => $extraLinks,
            'translations' => $translations,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $links
     * @return array<int, array{label: string, url: string}>
     */
    private function normalizeLinks(array $links): array
    {
        return collect($links)
            ->map(function ($link) {
                return [
                    'label' => trim((string) ($link['label'] ?? '')),
                    'url' => trim((string) ($link['url'] ?? '')),
                ];
            })
            ->filter(fn (array $link) => $link['label'] !== '' && $link['url'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function getStoredSettings(): array
    {
        $stored = config('welcome-onboarding.stored');
        if (is_array($stored)) {
            return $stored;
        }

        $value = env(self::ENV_KEY, '');
        if (!is_string($value) || $value === '') {
            return [];
        }

        try {
            $stored = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

            return is_array($stored) ? $stored : [];
        } catch (JsonException) {
            return [];
        }
    }
}
