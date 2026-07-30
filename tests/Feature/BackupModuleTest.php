<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Models\AdminUser;
use Modules\Auth\Models\User;
use Modules\Backup\Jobs\RunBackupJob;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BackupModuleTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTenants;

    /**
     * A back-office account.
     *
     * These screens are staff tools behind the `admin` guard, not customer features —
     * so this is an AdminUser from the separate `admins` table, and requests must be
     * made with actingAs($admin, 'admin'). A customer session has no standing here.
     */
    protected ?AdminUser $adminAccount = null;

    protected function admin(): AdminUser
    {
        // Memoised deliberately. It previously minted a fresh account per call, which
        // now breaks a test that makes two requests: AuthenticateSession stamps the
        // authenticated user's password hash into the session and re-checks it, so
        // swapping in a *different* account mid-session is correctly treated as a
        // hijacked session and evicted. Reusing one account is also what a real
        // administrator does.
        return $this->adminAccount ??= $this->adminUser();
    }

    public function test_backups_page_requires_a_back_office_session(): void
    {
        $user = User::query()->create([
            'name' => 'U', 'email' => 'u@test.local', 'password' => 'x',
        ]);

        // Customer sessions are redirected to the staff login rather than 403'd — see
        // AuthenticateAdmin.
        $this->actingAs($user)
            ->get('/admin/backups')
            ->assertRedirect(route('admin.login'));

        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/backups')
            ->assertOk()
            ->assertSee('Backups');
    }

    public function test_backup_list_shows_files_from_disk(): void
    {
        Storage::fake('backups');
        Storage::disk('backups')->put('MeetingNotes/2026-07-19-02-15-00.zip', str_repeat('x', 2048));

        $this->actingAs($this->admin(), 'admin')->get('/admin/backups')
            ->assertOk()
            ->assertSee('2026-07-19-02-15-00.zip');
    }

    public function test_run_backup_queues_job(): void
    {
        Queue::fake();

        $this->actingAs($this->admin(), 'admin')->post('/admin/backups/run')->assertRedirect();

        Queue::assertPushed(RunBackupJob::class, fn ($job) => $job->onlyDb === false);

        $this->actingAs($this->admin(), 'admin')->post('/admin/backups/run', ['only_db' => 1]);
        Queue::assertPushed(RunBackupJob::class, fn ($job) => $job->onlyDb === true);
    }

    public function test_download_and_delete_reject_traversal_and_non_zip(): void
    {
        Storage::fake('backups');
        Storage::disk('backups')->put('MeetingNotes/ok.zip', 'zipdata');

        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get('/admin/backups/download?path=MeetingNotes/ok.zip')->assertOk();
        $this->actingAs($admin, 'admin')->get('/admin/backups/download?path=../../.env')->assertNotFound();
        $this->actingAs($admin, 'admin')->get('/admin/backups/download?path=MeetingNotes/../../.env.zip')->assertNotFound();

        $this->actingAs($admin, 'admin')
            ->delete('/admin/backups', ['path' => 'MeetingNotes/ok.zip'])
            ->assertRedirect();
        Storage::disk('backups')->assertMissing('MeetingNotes/ok.zip');
    }

    public function test_settings_save_and_render(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->put('/admin/backups/settings', [
            'schedule_enabled' => 1,
            'daily_time' => '03:30',
            'notify_email' => 'ops@ceratine-labs.co.za'
        ])->assertRedirect();

        $this->assertSame('1', setting('backup.schedule_enabled'));
        $this->assertSame('03:30', setting('backup.daily_time'));
        $this->assertSame('ops@ceratine-labs.co.za', setting('backup.notify_email'));

        $this->actingAs($admin, 'admin')->get('/admin/backups')
            ->assertSee('03:30')
            ->assertSee('ops@ceratine-labs.co.za');

        $this->actingAs($admin, 'admin')->put('/admin/backups/settings', [
            'daily_time' => 'not-a-time'
        ])->assertSessionHasErrors('daily_time');
    }
}
