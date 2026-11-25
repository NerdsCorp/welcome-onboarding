<?php

namespace WelcomeOnboarding\Support;

use App\Traits\EnvironmentWriterTrait;
use Illuminate\Support\Arr;
use JsonException;

class SettingsRepository
{
    use EnvironmentWriterTrait;

    private const ENV_KEY = 'WELCOME_ONBOARDING_SETTINGS';
    private const ENV_PREFIX = 'WELCOME_ONBOARDING_SETTINGS_';

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
        // Store each setting separately. Base64 keeps arbitrary text safe for
        // dotenv while leaving each setting on its own environment-file line.
        $environment = [];
        foreach ($settings as $key => $value) {
            $environment[self::ENV_PREFIX.strtoupper($key)] = 'base64:'.base64_encode(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
        $this->writeToEnvironment($environment);
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
                    'intro_text' => $this->normalizeMultiline((string) ($translation['intro_text'] ?? '')),
                    'setup_button_label' => trim((string) ($translation['setup_button_label'] ?? '')),
                    'setup_button_url' => trim((string) ($translation['setup_button_url'] ?? '')),
                    'welcome_link_label' => trim((string) ($translation['welcome_link_label'] ?? '')),
                    'first_login_steps' => $this->normalizeMultiline((string) ($translation['first_login_steps'] ?? '')),
                    'closing_text' => $this->normalizeMultiline((string) ($translation['closing_text'] ?? '')),
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
            'intro_text' => $this->normalizeMultiline((string) ($data['intro_text'] ?? '')),
            'setup_button_label' => trim((string) ($data['setup_button_label'] ?? '')),
            'setup_button_url' => trim((string) ($data['setup_button_url'] ?? '')),
            'welcome_url' => trim((string) ($data['welcome_url'] ?? '')),
            'welcome_link_label' => trim((string) ($data['welcome_link_label'] ?? '')),
            'discord_url' => trim((string) ($data['discord_url'] ?? '')),
            'community_url' => trim((string) ($data['community_url'] ?? '')),
            'support_url' => trim((string) ($data['support_url'] ?? '')),
            'first_login_steps' => $this->normalizeMultiline((string) ($data['first_login_steps'] ?? '')),
            'closing_text' => $this->normalizeMultiline((string) ($data['closing_text'] ?? '')),
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

    private function normalizeMultiline(string $value): string
    {
        // Textareas can arrive with newline escape sequences from dotenv or
        // form serialization. Convert those to real line breaks for email output.
        return trim(str_replace(['\\r\\n', '\\n', '\\r'], "\n", $value));
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

        $separateSettings = [];
        foreach (array_keys(config('welcome-onboarding.defaults', [])) as $key) {
            $value = env(self::ENV_PREFIX.strtoupper($key));
            if (!is_string($value) || !str_starts_with($value, 'base64:')) {
                continue;
            }

            $decoded = base64_decode(substr($value, 7), true);
            if ($decoded === false) {
                continue;
            }

            try {
                $separateSettings[$key] = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }
        }
        if ($separateSettings !== []) {
            return $separateSettings;
        }

        $value = env(self::ENV_KEY, '');
        if (!is_string($value) || $value === '') {
            return [];
        }

        // New values are stored as base64 to remain safe in dotenv files.
        // Keep accepting the previous raw JSON format for existing installs.
        if (str_starts_with($value, 'base64:')) {
            $decoded = base64_decode(substr($value, 7), true);
            if ($decoded === false) {
                return [];
            }
            $value = $decoded;
        }

        try {
            $stored = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

            if (is_array($stored)) {
                foreach (['intro_text', 'first_login_steps', 'closing_text'] as $field) {
                    if (isset($stored[$field]) && is_string($stored[$field])) {
                        $stored[$field] = $this->normalizeMultiline($stored[$field]);
                    }
                }

                foreach (($stored['translations'] ?? []) as $index => $translation) {
                    if (!is_array($translation)) {
                        continue;
                    }

                    foreach (['intro_text', 'first_login_steps', 'closing_text'] as $field) {
                        if (isset($translation[$field]) && is_string($translation[$field])) {
                            $stored['translations'][$index][$field] = $this->normalizeMultiline($translation[$field]);
                        }
                    }
                }
            }

            return is_array($stored) ? $stored : [];
        } catch (JsonException) {
            return [];
        }
    }
}
