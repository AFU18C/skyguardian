<?php

namespace Tests\Feature;

use Tests\TestCase;

class DeploymentSafetyTest extends TestCase
{
    public function test_deployment_preserves_runtime_storage_and_validates_production(): void
    {
        $script = file_get_contents(base_path('deploy/deploy.sh'));
        $workflow = file_get_contents(base_path('.github/workflows/deploy.yml'));

        $this->assertIsString($script);
        $this->assertStringContainsString('RELEASES_DIR=', $script);
        $this->assertStringContainsString('atomic_link', $script);
        $this->assertStringContainsString('rollback()', $script);
        $this->assertStringContainsString('skyguardian-backup.service', $script);
        $this->assertStringContainsString('sudo -u www-data test -r "$SHARED_DIR/.env"', $script);
        $this->assertStringContainsString('https://skyguardian.pp.ua/admin/login', $script);
        $this->assertLessThan(
            strpos($script, 'sudo -u www-data php artisan config:cache'),
            strpos($script, 'mv "$BUILD_DIR" "$RELEASE_DIR"'),
            'Release directory must be finalized before Laravel caches absolute paths.',
        );
        $this->assertStringContainsString('bash deploy/deploy.sh', $workflow);
        $this->assertStringContainsString('workflow_run:', $workflow);
        $this->assertStringContainsString("github.event.workflow_run.conclusion == 'success'", $workflow);
        $this->assertStringNotContainsString("branches:\n      - main", $workflow);
    }

    public function test_backup_fails_on_incomplete_archives_and_verifies_restore_files(): void
    {
        $script = file_get_contents(base_path('deploy/backup/skyguardian-full-backup.sh'));

        $this->assertIsString($script);
        $this->assertStringNotContainsString('--ignore-failed-read', $script);
        $this->assertStringContainsString("grep -Fx 'skyguardian/.env'", $script);
        $this->assertStringContainsString('sha256sum -c SHA256SUMS', $script);
        $this->assertStringContainsString('tar -xzf "$FINAL_ARCHIVE"', $script);
        $this->assertStringContainsString('BACKUP_RETENTION_COUNT=3', $script);
        $this->assertStringContainsString('${BACKUPS[@]:$BACKUP_RETENTION_COUNT}', $script);
    }
}
