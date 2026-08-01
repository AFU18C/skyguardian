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
        $this->assertStringContainsString('--exclude="storage"', $script);
        $this->assertStringContainsString('--exclude="public/storage"', $script);
        $this->assertStringContainsString('chown github-runner:www-data .env', $script);
        $this->assertStringContainsString('sudo -u www-data test -r .env', $script);
        $this->assertStringContainsString('https://skyguardian.pp.ua/admin/login', $script);
        $this->assertStringContainsString('bash deploy/deploy.sh', $workflow);
    }
}
