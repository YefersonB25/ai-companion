<?php

namespace App\Console\Commands;

use App\Models\AppVersion;
use Illuminate\Console\Command;

class PublishAppVersion extends Command
{
    protected $signature = 'app:release
        {version   : Semver string, e.g. 1.2.0}
        {--platform=android : android or ios}
        {--required : Mark as a forced update}
        {--url=     : APK/IPA download URL}';

    protected $description = 'Publish a new app version and prompt for changelog entries';

    public function handle(): void
    {
        $version  = $this->argument('version');
        $platform = $this->option('platform');

        $this->info("Publishing {$platform} v{$version}");

        // Read current version_code from app.json if available
        $appJson     = base_path('../ai-companion-mobile/app.json');
        $versionCode = 1;
        if (file_exists($appJson)) {
            $data = json_decode(file_get_contents($appJson), true);
            $versionCode = $data['expo']['android']['versionCode'] ?? 1;
        }
        $versionCode = (int) $this->ask('versionCode (Android integer)', $versionCode);

        // Collect changelog lines
        $changelog = [];
        $this->line('Enter changelog items one by one. Empty line to finish:');
        while (true) {
            $item = $this->ask('  - ');
            if (blank($item)) break;
            $changelog[] = $item;
        }

        if (empty($changelog)) {
            $this->error('Changelog cannot be empty.');
            return;
        }

        $url      = $this->option('url') ?: $this->ask('Download URL (leave empty if not ready yet)');
        $required = $this->option('required') || $this->confirm('Is this a required update?', false);

        AppVersion::updateOrCreate(
            ['platform' => $platform, 'version' => $version],
            [
                'version_code' => $versionCode,
                'changelog'    => $changelog,
                'download_url' => $url ?: null,
                'is_required'  => $required,
            ]
        );

        $this->info("✓ Version {$version} published for {$platform}.");
        $this->table(['Field', 'Value'], [
            ['version',      $version],
            ['version_code', $versionCode],
            ['required',     $required ? 'Yes' : 'No'],
            ['download_url', $url ?: '(pending)'],
            ['changelog',    implode(' | ', $changelog)],
        ]);
    }
}
