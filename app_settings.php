<?php

declare(strict_types=1);

if (!function_exists('appSettingsLoadConfig')) {
    function appSettingsLoadConfig(): array
    {
        $configFile = __DIR__ . '/db_config.php';
        if (!is_file($configFile)) {
            return [];
        }

        $loaded = require $configFile;
        return is_array($loaded) ? $loaded : [];
    }
}

if (!function_exists('appSettingsSaveConfig')) {
    function appSettingsSaveConfig(array $config): void
    {
        $configFile = __DIR__ . '/db_config.php';
        $export = var_export($config, true);
        $content = "<?php\n\nreturn " . $export . ";\n";
        if (file_put_contents($configFile, $content, LOCK_EX) === false) {
            throw new RuntimeException('Nie udało się zapisać pliku db_config.php.');
        }
    }
}

if (!function_exists('appSettingsOrganizationDefaults')) {
    function appSettingsOrganizationDefaults(): array
    {
        return [
            'name' => 'Muzeum Książki Artystycznej w Łodzi',
            'website' => 'https://mkalodz.pl',
            'address' => '',
            'contact_email' => '',
        ];
    }
}

if (!function_exists('appSettingsGetOrganizationProfile')) {
    function appSettingsGetOrganizationProfile(?array $config = null): array
    {
        $config = is_array($config) ? $config : appSettingsLoadConfig();
        $profile = $config['organization_profile'] ?? [];
        if (!is_array($profile)) {
            $profile = [];
        }

        $defaults = appSettingsOrganizationDefaults();

        return [
            'name' => trim((string)($profile['name'] ?? $defaults['name'])),
            'website' => trim((string)($profile['website'] ?? $defaults['website'])),
            'address' => trim((string)($profile['address'] ?? $defaults['address'])),
            'contact_email' => trim((string)($profile['contact_email'] ?? $defaults['contact_email'])),
        ];
    }
}
