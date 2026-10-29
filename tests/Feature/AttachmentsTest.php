<?php

use App\Billing\BillingGateway;
use App\Enums\Role;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordingGateway;

beforeEach(function () {
    Storage::fake('local');
    $this->owner = ownerWithTeam();
    $this->team = $this->owner->currentTeam;
    $this->task = taskFor($this->team);
});

function taskFor(Team $team): Task
{
    return asTenant($team, fn () => Task::factory()->create(['project_id' => Project::factory()->create()->id]));
}

function pdf(string $name = 'report.pdf', int $kb = 10): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".str_repeat('x', $kb * 1024));
}

/** A real file on disk, so its type is judged from what it contains. (Laravel's fake uploads report the type of their *name*.) */
function realFile(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'att');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

function upload(User $as, Task $task, ?UploadedFile $file = null): TestResponse
{
    return test()->actingAs($as)->from('/projects')->post(
        "/projects/{$task->project_id}/tasks/{$task->id}/attachments",
        ['file' => $file ?? pdf()],
    );
}

function attach(Team $team, Task $task, ?User $by = null, int $kb = 10, string $name = 'report.pdf'): Attachment
{
    $path = "attachments/{$team->id}/".Str::uuid();
    Storage::disk('local')->put($path, str_repeat('x', $kb * 1024));

    return asTenant($team, fn () => Attachment::create([
        'task_id' => $task->id, 'uploaded_by' => $by?->id, 'name' => $name, 'path' => $path, 'mime' => 'application/pdf', 'size' => $kb * 1024,
    ]));
}

function colleague(Team $team, Role $role = Role::Member): User
{
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $team->addMember($user, $role);

    return $user;
}

describe('uploading', function () {
    it('stores the file privately under the team and records who added it', function () {
        upload($this->owner, $this->task)->assertSessionHasNoErrors()->assertRedirect('/projects')->assertSessionHas('success');

        $attachment = asTenant($this->team, fn () => Attachment::sole());
        expect($attachment->name)->toBe('report.pdf')
            ->and($attachment->mime)->toBe('application/pdf')
            ->and($attachment->size)->toBe(strlen("%PDF-1.4\n") + 10 * 1024)
            ->and($attachment->task_id)->toBe($this->task->id)
            ->and($attachment->uploaded_by)->toBe($this->owner->id)
            ->and($attachment->tenant_id)->toBe($this->team->id)
            ->and($attachment->path)->toStartWith("attachments/{$this->team->id}/")->not->toContain('report');
        Storage::disk('local')->assertExists($attachment->path);
    });

    it('never puts the original file name into the storage path', function () {
        upload($this->owner, $this->task, pdf('../../etc/passwd-notes.pdf'));

        $attachment = asTenant($this->team, fn () => Attachment::sole());
        expect($attachment->path)->toMatch('#^attachments/\d+/[0-9a-f-]{36}$#');
    });

    it('keeps a clean version of the name for display', function () {
        upload($this->owner, $this->task, pdf("../../Q3 \"plan\"\n<b>.pdf"));

        $name = asTenant($this->team, fn () => Attachment::sole()->name);
        expect($name)->not->toContain('/')->not->toContain('..')->not->toContain("\n")->not->toContain('<')->toEndWith('.pdf');
    });

    it('shortens a very long name', function () {
        upload($this->owner, $this->task, pdf(str_repeat('a', 300).'.pdf'));

        expect(strlen(asTenant($this->team, fn () => Attachment::sole()->name)))->toBeLessThanOrEqual(255);
    });

    it('records it in the audit log', function () {
        upload($this->owner, $this->task);

        $entry = asTenant($this->team, fn () => AuditLog::where('action', 'attachment.added')->sole());
        expect($entry->summary)->toBe("Attached “report.pdf” to task “{$this->task->title}”")->and($entry->user_id)->toBe($this->owner->id);
    });

    it('accepts the document and image types on the allowlist', function (UploadedFile $file) {
        upload($this->owner, $this->task, $file)->assertSessionHasNoErrors();

        expect(asTenant($this->team, fn () => Attachment::count()))->toBe(1);
    })->with([
        'pdf' => fn () => pdf(),
        'png' => fn () => UploadedFile::fake()->image('photo.png'),
        'jpg' => fn () => UploadedFile::fake()->image('photo.jpg'),
        'gif' => fn () => UploadedFile::fake()->image('photo.gif'),
        'webp' => fn () => UploadedFile::fake()->image('photo.webp'),
        'txt' => fn () => UploadedFile::fake()->createWithContent('notes.txt', 'hello'),
        'csv' => fn () => UploadedFile::fake()->createWithContent('data.csv', "a,b\n1,2\n"),
        'md' => fn () => UploadedFile::fake()->createWithContent('readme.md', "# Title\n"),
    ]);

    it('refuses types that could run or render in the browser', function (UploadedFile $file) {
        upload($this->owner, $this->task, $file)->assertSessionHasErrors('file');

        expect(asTenant($this->team, fn () => Attachment::count()))->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
    })->with([
        'exe' => fn () => UploadedFile::fake()->createWithContent('setup.exe', 'MZ binary'),
        'php' => fn () => UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;'),
        'html' => fn () => UploadedFile::fake()->createWithContent('page.html', '<html><script>alert(1)</script></html>'),
        'svg' => fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        'js' => fn () => UploadedFile::fake()->createWithContent('app.js', 'alert(1)'),
        'html disguised as png' => fn () => realFile('photo.png', '<html><script>alert(1)</script></html>'),
        'php disguised as pdf' => fn () => realFile('invoice.pdf', '<?php system($_GET["c"]);'),
        'script disguised as txt' => fn () => realFile('notes.txt', "<!DOCTYPE html>\n<html><body onload=\"alert(1)\"></body></html>"),
    ]);

    it('needs a file', function () {
        test()->actingAs($this->owner)->from('/projects')->post("/projects/{$this->task->project_id}/tasks/{$this->task->id}/attachments", [])
            ->assertSessionHasErrors('file');
    });

    it('refuses a file bigger than the per-file limit', function () {
        config(['attachments.max_file_kb' => 100]);

        upload($this->owner, $this->task, pdf('big.pdf', kb: 101))->assertSessionHasErrors('file');
        upload($this->owner, $this->task, pdf('ok.pdf', kb: 90))->assertSessionHasNoErrors();
    });

    it('is open to any member of the team, like tasks', function () {
        $member = colleague($this->team);

        upload($member, $this->task)->assertSessionHasNoErrors();

        expect(asTenant($this->team, fn () => Attachment::sole()->uploaded_by))->toBe($member->id);
    });

    it('is for signed-in users only', function () {
        $this->post("/projects/{$this->task->project_id}/tasks/{$this->task->id}/attachments", ['file' => pdf()])->assertRedirect('/login');
    });

    it('is limited to 20 uploads a minute per user', function () {
        foreach (range(1, 20) as $i) {
            upload($this->owner, $this->task, UploadedFile::fake()->createWithContent("n{$i}.txt", 'x'))->assertSessionHasNoErrors();
        }

        upload($this->owner, $this->task, UploadedFile::fake()->createWithContent('last.txt', 'x'))->assertStatus(429);
    });
});

describe('the team storage quota', function () {
    it('allows uploads while the plan has room and refuses the one that does not fit', function () {
        config(['billing.plans.free.limits.storage_mb' => 1]);
        $free = ownerWithTeam('free');
        $task = taskFor($free->currentTeam);

        upload($free, $task, pdf('a.pdf', kb: 600))->assertSessionHasNoErrors();
        upload($free, $task, pdf('b.pdf', kb: 600))->assertRedirect()->assertSessionHas('upgrade');

        expect(asTenant($free->currentTeam, fn () => Attachment::count()))->toBe(1)
            ->and(session('upgrade'))->toContain('1 MB')->toContain('Upgrade to Pro');
    });

    it('is judged by the plan in force: a failed payment past grace uses the Free quota', function () {
        config(['billing.plans.free.limits.storage_mb' => 1]);
        $this->team->forceFill(['plan' => 'business', 'payment_failed_at' => now()->subDays(10), 'payment_grace_ends_at' => now()->subDay()])->save();

        upload($this->owner, $this->task, pdf('a.pdf', kb: 1100))->assertRedirect()->assertSessionHas('upgrade');
    });

    it('never limits an unlimited plan', function () {
        config(['attachments.max_file_kb' => 5000]);
        $this->team->forceFill(['plan' => 'business'])->save();
        attach($this->team, $this->task, kb: 4000);

        upload($this->owner, $this->task, pdf('more.pdf', kb: 3000))->assertSessionHasNoErrors()->assertSessionMissing('upgrade');
    });

    it('counts what the team already stores, and deleting a file frees the room', function () {
        config(['billing.plans.free.limits.storage_mb' => 1]);
        $free = ownerWithTeam('free');
        $task = taskFor($free->currentTeam);
        $existing = attach($free->currentTeam, $task, kb: 900);

        upload($free, $task, pdf('a.pdf', kb: 200))->assertSessionHas('upgrade');

        $this->actingAs($free)->delete("/attachments/{$existing->id}")->assertRedirect();
        upload($free, $task, pdf('a.pdf', kb: 200))->assertSessionHasNoErrors();
    });

    it('does not count another team\'s files', function () {
        config(['billing.plans.free.limits.storage_mb' => 1]);
        $free = ownerWithTeam('free');
        attach($this->team, $this->task, kb: 900);

        upload($free, taskFor($free->currentTeam), pdf('a.pdf', kb: 900))->assertSessionHasNoErrors();
    });

    it('keeps existing files readable after a downgrade, and only blocks new ones', function () {
        config(['billing.plans.free.limits.storage_mb' => 1]);
        $attachment = attach($this->team, $this->task, kb: 2000);
        $this->team->forceFill(['plan' => 'free'])->save();

        $this->actingAs($this->owner)->get("/attachments/{$attachment->id}")->assertOk();
        upload($this->owner, $this->task, pdf('a.pdf', kb: 10))->assertSessionHas('upgrade');
    });
});

describe('downloading', function () {
    it('serves the file as a download with its own name, never inline', function () {
        $attachment = attach($this->team, $this->task, $this->owner, name: 'Q3 report.pdf');

        $response = $this->actingAs($this->owner)->get("/attachments/{$attachment->id}")->assertOk();

        expect($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('Q3 report.pdf')
            ->and($response->headers->get('Content-Type'))->toContain('application/pdf')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->streamedContent())->toBe(str_repeat('x', 10 * 1024));
    });

    it('is open to every member of the team', function () {
        $attachment = attach($this->team, $this->task, $this->owner);

        $this->actingAs(colleague($this->team))->get("/attachments/{$attachment->id}")->assertOk();
    });

    it('is not possible from another team, whatever the id', function () {
        $attachment = attach($this->team, $this->task, $this->owner);
        $stranger = ownerWithTeam();

        $this->actingAs($stranger)->get("/attachments/{$attachment->id}")->assertNotFound();
    });

    it('is not possible after leaving the team', function () {
        $attachment = attach($this->team, $this->task, $this->owner);
        $leaver = colleague($this->team);
        Team::factory()->create()->addMember($leaver, Role::Member);
        $this->team->removeMember($leaver);

        $this->actingAs($leaver->refresh())->get("/attachments/{$attachment->id}")->assertNotFound();
    });

    it('is for signed-in users only', function () {
        $attachment = attach($this->team, $this->task, $this->owner);

        $this->get("/attachments/{$attachment->id}")->assertRedirect('/login');
    });

    it('says not found when the file itself has gone missing', function () {
        $attachment = attach($this->team, $this->task, $this->owner);
        Storage::disk('local')->delete($attachment->path);

        $this->actingAs($this->owner)->get("/attachments/{$attachment->id}")->assertNotFound();
    });
});

describe('deleting', function () {
    it('removes the row and the file and records it', function () {
        $attachment = attach($this->team, $this->task, $this->owner, name: 'old.pdf');

        $this->actingAs($this->owner)->delete("/attachments/{$attachment->id}")->assertRedirect()->assertSessionHas('success');

        expect(asTenant($this->team, fn () => Attachment::count()))->toBe(0);
        Storage::disk('local')->assertMissing($attachment->path);
        expect(asTenant($this->team, fn () => AuditLog::where('action', 'attachment.deleted')->sole()->summary))->toBe('Removed “old.pdf” from a task');
    });

    it('is not possible for another team\'s file', function () {
        $attachment = attach($this->team, $this->task, $this->owner);

        $this->actingAs(ownerWithTeam())->delete("/attachments/{$attachment->id}")->assertNotFound();

        Storage::disk('local')->assertExists($attachment->path);
        expect(asTenant($this->team, fn () => Attachment::count()))->toBe(1);
    });

    it('is not possible to add to another team\'s task', function () {
        $stranger = ownerWithTeam();

        upload($stranger, $this->task)->assertNotFound();

        expect(asTenant($this->team, fn () => Attachment::count()))->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
    });
});

describe('cleaning up files', function () {
    it('removes the files of a deleted task', function () {
        $one = attach($this->team, $this->task);
        $keep = attach($this->team, taskFor($this->team));

        $this->actingAs($this->owner)->delete("/projects/{$this->task->project_id}/tasks/{$this->task->id}")->assertRedirect();

        Storage::disk('local')->assertMissing($one->path);
        Storage::disk('local')->assertExists($keep->path);
    });

    it('removes the files of every task of a deleted project', function () {
        $second = asTenant($this->team, fn () => Task::factory()->create(['project_id' => $this->task->project_id]));
        $a = attach($this->team, $this->task);
        $b = attach($this->team, $second);
        $other = attach($this->team, taskFor($this->team));

        $this->actingAs($this->owner)->delete("/projects/{$this->task->project_id}")->assertRedirect();

        Storage::disk('local')->assertMissing($a->path);
        Storage::disk('local')->assertMissing($b->path);
        Storage::disk('local')->assertExists($other->path);
    });

    it('removes everything of a deleted team, and nothing of another', function () {
        $this->app->instance(BillingGateway::class, new RecordingGateway);
        $mine = attach($this->team, $this->task, $this->owner);
        $otherTeam = ownerWithTeam()->currentTeam;
        $theirs = attach($otherTeam, taskFor($otherTeam));

        $this->actingAs($this->owner)->delete('/team', ['password' => 'password', 'name' => $this->team->name])->assertRedirect();

        Storage::disk('local')->assertMissing($mine->path);
        Storage::disk('local')->assertExists($theirs->path);
        expect(Storage::disk('local')->exists("attachments/{$this->team->id}"))->toBeFalse();
    });

    it('removes the files of a team deleted along with its sole owner\'s account', function () {
        $this->app->instance(BillingGateway::class, new RecordingGateway);
        $mine = attach($this->team, $this->task, $this->owner);

        $this->actingAs($this->owner)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

        Storage::disk('local')->assertMissing($mine->path);
    });

    it('keeps a team\'s files when the member who uploaded them deletes their account', function () {
        $uploader = colleague($this->team);
        $file = attach($this->team, $this->task, $uploader);

        $this->actingAs($uploader)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

        Storage::disk('local')->assertExists($file->path);
        expect(DB::table('attachments')->where('id', $file->id)->value('uploaded_by'))->toBeNull();
    });
});

describe('on the project page and elsewhere', function () {
    it('lists each task\'s files and the team\'s storage use', function () {
        config(['billing.plans.free.limits.storage_mb' => 25]);
        $free = ownerWithTeam('free');
        $task = taskFor($free->currentTeam);
        attach($free->currentTeam, $task, $free, kb: 512, name: 'spec.pdf');

        $this->actingAs($free)->get("/projects/{$task->project_id}")->assertInertia(fn ($page) => $page
            ->where('tasks.data.0.id', $task->id)
            ->where('tasks.data.0.attachments.0.name', 'spec.pdf')
            ->where('tasks.data.0.attachments.0.size', 512 * 1024)
            ->where('tasks.data.0.attachments.0.uploader', $free->name)
            ->has('tasks.data.0.attachments.0', fn ($a) => $a->hasAll(['id', 'name', 'size', 'created_at', 'uploader'])->missing('path'))
            ->where('storage.used_mb', 1)
            ->where('storage.limit_mb', 25));
    });

    it('does not show another team\'s files, or ever send the storage path', function () {
        $other = ownerWithTeam()->currentTeam;
        attach($other, taskFor($other));
        $mine = attach($this->team, $this->task, $this->owner);

        $html = $this->actingAs($this->owner)->get("/projects/{$this->task->project_id}")->getContent();

        expect($html)->not->toContain($mine->path)->not->toContain("attachments/{$other->id}");
    });

    it('shows the storage allowance on the plans', function () {
        $this->actingAs($this->owner)->get('/billing')->assertInertia(fn ($page) => $page
            ->where('plans.0.limits.storage_mb', 25)
            ->where('plans.1.limits.storage_mb', 1000)
            ->where('plans.2.limits.storage_mb', null)
            ->has('usage.storage_mb'));
    });

    it('is part of the personal data download as names and sizes, not the files', function () {
        attach($this->team, $this->task, $this->owner, kb: 5, name: 'mine.pdf');
        attach($this->team, $this->task, colleague($this->team), name: 'theirs.pdf');

        $json = $this->actingAs($this->owner)->from('/profile')->post('/profile/export', ['password' => 'password'])->streamedContent();
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        expect($data['uploads'])->toHaveCount(1)
            ->and(array_keys($data['uploads'][0]))->toBe(['team_id', 'name', 'mime', 'size', 'created_at'])
            ->and($data['uploads'][0])->toMatchArray(['team_id' => $this->team->id, 'name' => 'mine.pdf', 'size' => 5 * 1024])
            ->and($json)->not->toContain('attachments/');
    });
});
