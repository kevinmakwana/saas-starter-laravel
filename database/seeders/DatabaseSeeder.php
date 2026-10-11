<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public const DEMO_EMAIL = 'demo@acme.test';

    public const DEMO_PASSWORD = 'password';

    public function run(): void
    {
        $owner = $this->user('Dana Demo', self::DEMO_EMAIL);
        $admin = $this->user('Avery Admin', 'admin@acme.test');
        $member = $this->user('Morgan Member', 'member@acme.test');

        $team = Team::firstOrCreate(['owner_id' => $owner->id, 'name' => 'Acme Demo'], ['plan' => 'business']);
        $team->forceFill(['plan' => 'business'])->save();
        $team->addMember($owner, Role::Owner);
        $team->addMember($admin, Role::Admin);
        $team->addMember($member, Role::Member);
        foreach ([$owner, $admin, $member] as $user) {
            $user->switchTeam($team);
        }

        app(TenantContext::class)->run($team, function () use ($owner, $admin) {
            if (Project::count() > 0) {
                return;
            }

            $audit = app(AuditLogger::class);
            $audit->record('team.created', 'Created the Acme Demo team', null, $owner);
            $audit->record('member.invited', 'Invited Avery Admin as Admin', null, $owner);
            $audit->record('billing.subscribed', 'Started the Business plan', null, $owner);

            $projects = [
                'Website relaunch' => ['Audit current pages', 'Draft new homepage copy', 'Design hero section', 'Set up redirects', 'Launch checklist'],
                'Mobile app v2' => ['Collect beta feedback', 'Fix onboarding crash', 'Ship push notifications'],
                'Customer onboarding' => ['Write welcome email', 'Record product tour', 'Define success metrics'],
            ];

            foreach ($projects as $name => $titles) {
                $project = Project::create(['name' => $name, 'description' => "Demo project: {$name}."]);
                $audit->record('project.created', "Created project “{$name}”", $project, $admin);

                foreach ($titles as $i => $title) {
                    Task::create([
                        'project_id' => $project->id,
                        'title' => $title,
                        'status' => ['done', 'doing', 'todo'][$i % 3],
                    ]);
                }
            }
        });
    }

    private function user(string $name, string $email): User
    {
        return User::firstOrCreate(['email' => $email], [
            'name' => $name,
            'password' => Hash::make(self::DEMO_PASSWORD),
            'email_verified_at' => now(),
        ]);
    }
}
