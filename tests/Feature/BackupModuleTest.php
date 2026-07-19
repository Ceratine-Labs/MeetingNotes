<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Auth\Models\User;
use Modules\Backup\Jobs\RunBackupJob;
use Tests\TestCase;

class BackupModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::query()->create([
            'name' => 'A', 'email' => uniqid() . '@test.local', 'password' => 'x', 'role' => User::ROLE_ADMIN,
        ]);
    }

    public function test_backups_page_is_admin_only(): void
    {
        $user = User::query()->create([
            'name' => 'U', 'email' => 'u@test.local', 'password' => 'x', 'role' => User::ROLE_USER,
        ]);

        $this->actingAs($user)->get('/app/admin/backups')->assertForbidden();
        $this->actingAs($this->admin())->get('/app/admin/backups')->assertOk()->assertSee('Backups');
    }

    public function test_backup_list_shows_files_from_disk(): void
    {
        Storage::fake('backups');
        Storage::disk('backups')->put('MeetingNotes/2026-07-19-02-15-00.zip', str_repeat('x', 2048));

        $this->actingAs($this->admin())->get('/app/admin/backups')
            ->assertOk()
            ->assertSee('2026-07-19-02-15-00.zip');
    }

    public function test_run_backup_queues_job(): void
    {
        Queue::fake();

        $this->actingAs($this->admin())->post('/app/admin/backups/run')->assertRedirect();

        Queue::assertPushed(RunBackupJob::class, fn ($job) => $job->onlyDb === false);

        $this->actingAs($this->admin())->post('/app/admin/backups/run', ['only_db' => 1]);
        Queue::assertPushed(RunBackupJob::class, fn ($job) => $job->onlyDb === true);
    }

    public function test_download_and_delete_reject_traversal_and_non_zip(): void
    {
        Storage::fake('backups');
        Storage::disk('backups')->put('MeetingNotes/ok.zip', 'zipdata');

        $admin = $this->admin();

        $this->actingAs($admin)->get('/app/admin/backups/download?path=MeetingNotes/ok.zip')->assertOk();
        $this->actingAs($admin)->get('/app/admin/backups/download?path=../../.env')->assertNotFound();
        $this->actingAs($admin)->get('/app/admin/backups/download?path=MeetingNotes/../../.env.zip')->assertNotFound();

        $this->actingAs($admin)
            ->delete('/app/admin/backups', ['path' => 'MeetingNotes/ok.zip'])
            ->assertRedirect();
        Storage::disk('backups')->assertMissing('MeetingNotes/ok.zip');
    }

    public function test_settings_save_and_render(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/app/admin/backups/settings', [
            'schedule_enabled' => 1,
            'daily_time' => '03:30',
            'notify_email' => 'ops@ceratine-labs.co.za',
        ])->assertRedirect();

        $this->assertSame('1', setting('backup.schedule_enabled'));
        $this->assertSame('03:30', setting('backup.daily_time'));
        $this->assertSame('ops@ceratine-labs.co.za', setting('backup.notify_email'));

        $this->actingAs($admin)->get('/app/admin/backups')
            ->assertSee('03:30')
            ->assertSee('ops@ceratine-labs.co.za');

        $this->actingAs($admin)->put('/app/admin/backups/settings', [
            'daily_time' => 'not-a-time',
        ])->assertSessionHasErrors('daily_time');
    }
}
